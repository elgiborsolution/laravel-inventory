<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\Bridges\NullAccountingBridge;
use ESolution\Inventory\Bridges\NullApprovalBridge;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\Contracts\MovementPolicyRegistry;
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\DTO\ReversalRequest;
use ESolution\Inventory\DTO\TransferData;
use ESolution\Inventory\Enums\DocumentStatus;
use ESolution\Inventory\Events\DocumentPosted;
use ESolution\Inventory\Models\CostAdjustment;
use ESolution\Inventory\Models\CostLayer;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\DocumentLine;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\StockLedger;
use Illuminate\Support\Facades\DB;

/** Atomic, exact-cost operations for standard, untracked FIFO warehouse stock. */
final class TransferReversalService
{
    public function __construct(
        private readonly WorkflowEngine $workflow,
        private readonly StockCardManager $cards,
        private readonly StockAvailabilityService $availability,
        private readonly MovementPolicyRegistry $policies,
        private readonly AccountingBridge $accounting,
        private readonly ApprovalBridge $approval,
        private readonly PolicyEngine $postingPolicy,
    ) {}

    public function transfer(TransferData $data): Document
    {
        $this->guard();
        $this->validateKey($data->externalId);
        if ($data->lines === [] || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data->trxDate)
            || date('Y-m-d', strtotime($data->trxDate)) !== $data->trxDate) {
            throw new \DomainException('Transfer requires lines and a valid transaction date.');
        }
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->assertPosting(new DocumentData(
            'warehouse_transfer',
            $data->organizationId,
            $data->trxDate,
            $data->lines,
            $data->externalId,
            $data->sourceType,
            $data->sourceId,
        ));

        return DB::transaction(function () use ($data, $hash): Document {
            $this->lockItems(array_map(fn(LineData $line): int => $line->itemId, $data->lines));
            $existing = Document::query()->where('organization_id', $data->organizationId)
                ->where('source_type', $data->sourceType)->where('external_id', $data->externalId)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                return $this->retry($existing, $hash);
            }
            $document = Document::create([
                'document_type' => 'warehouse_transfer', 'organization_id' => $data->organizationId,
                'source_type' => $data->sourceType, 'source_id' => $data->sourceId,
                'external_id' => $data->externalId, 'idempotency_hash' => $hash,
                'trx_date' => $data->trxDate, 'status' => DocumentStatus::DRAFT,
            ]);
            foreach ($data->lines as $index => $input) {
                if (! is_finite($input->qty) || $input->qty <= 0 || round($input->qty, 6) !== $input->qty
                    || $input->qtyBonus !== 0.0 || $input->meta !== [] || $input->unitCost !== null
                    || $input->warehouseId === $data->targetWarehouseId || $input->transactionPrice !== null || $input->discountPerUnit !== 0.0) {
                    throw new \DomainException('Transfer requires positive six-decimal quantities, distinct warehouses, and no cost/bonus/metadata override.');
                }
                $out = $this->line($document, $input, $index * 2 + 1);
                $in = $this->line($document, new LineData($input->itemId, $input->uomId, $data->targetWarehouseId, $input->qty), $index * 2 + 2);
                $this->assertAvailable($out, $input->qty);
                $remaining = $input->qty;
                $layers = CostLayer::query()->where('item_id', $input->itemId)
                    ->where('scope_type', 'warehouse')->where('scope_id', $input->warehouseId)
                    ->where('remaining_qty', '>', 0)->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
                foreach ($layers as $layer) {
                    if ($remaining <= 0.0000001) {
                        break;
                    }
                    if ($layer->batch_id !== null || $layer->is_negative) {
                        throw new \DomainException('Transfer requires untracked positive cost layers.');
                    }
                    $qty = min($remaining, (float) $layer->remaining_qty);
                    $layer->remaining_qty = round((float) $layer->remaining_qty - $qty, 6);
                    $layer->save();
                    $target = CostLayer::create([
                        'item_id' => $input->itemId, 'scope_type' => 'warehouse', 'scope_id' => $data->targetWarehouseId,
                        'received_qty' => $qty, 'remaining_qty' => $qty, 'unit_cost' => $layer->unit_cost,
                        'received_at' => $data->trxDate, 'source_document_id' => $document->id,
                    ]);
                    $this->entry($out, 'out', $qty, (float) $layer->unit_cost, (int) $layer->id);
                    $this->entry($in, 'in', $qty, (float) $layer->unit_cost, (int) $target->id);
                    $remaining = round($remaining - $qty, 6);
                }
                if ($remaining > 0.0000001) {
                    throw new \DomainException('Insufficient cost layers for transfer.');
                }
            }

            return $this->finish($document);
        }, 3);
    }

    public function reverse(ReversalRequest $request): Document
    {
        $this->guard();
        if (trim($request->reason) === '') {
            throw new \DomainException('Reversal requires a reason.');
        }
        $key = $request->externalId ?? 'reversal:' . $request->documentId;
        $this->validateKey($key);
        $hash = hash('sha256', json_encode([$request->documentId, $request->reason, $key], JSON_THROW_ON_ERROR));
        $itemIds = Document::query()->findOrFail($request->documentId)->lines()
            ->pluck('item_id')->map(fn($id): int => (int) $id)->all();

        return DB::transaction(function () use ($request, $key, $hash, $itemIds): Document {
            $this->lockItems($itemIds);
            $original = Document::query()->lockForUpdate()->findOrFail($request->documentId);
            $existing = Document::query()->where('reversal_of_id', $original->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $this->retry($existing, $hash);
            }
            if ($original->status !== DocumentStatus::POSTED || $original->posting_completed_at === null
                || $original->reversal_of_id !== null || ! in_array($original->document_type, [
                    'purchase_receipt', 'positive_adjustment', 'goods_issue', 'negative_adjustment', 'scrap', 'warehouse_transfer',
                ], true) || ! empty($original->meta['_reservation_consumptions'])) {
                throw new \DomainException('Document cannot be reversed by the standard stock reversal service.');
            }
            $this->assertPosting(new DocumentData(
                'reversal',
                (int) $original->organization_id,
                now()->toDateString(),
                [],
                $key,
                'inventory_reversal',
                (string) $original->id,
            ));
            $document = Document::create([
                'document_type' => 'reversal', 'organization_id' => $original->organization_id,
                'source_type' => 'inventory_reversal', 'source_id' => (string) $original->id,
                'external_id' => $key, 'idempotency_hash' => $hash, 'trx_date' => now()->toDateString(),
                'status' => DocumentStatus::DRAFT, 'reversal_of_id' => $original->id,
                'reversal_reason' => $request->reason,
            ]);
            $entries = StockLedger::query()->whereIn('document_line_id', $original->lines()->select('id'))->orderBy('id')->get();
            if ($entries->isEmpty()) {
                throw new \DomainException('Document has no stock effects to reverse.');
            }
            // Validate every original layer before writing any inverse movement.
            foreach ($entries->groupBy('cost_layer_id') as $layerId => $effects) {
                $layer = CostLayer::query()->lockForUpdate()->findOrFail($layerId);
                $delta = $effects->sum(fn(StockLedger $entry): float => ($entry->direction === 'in' ? -1 : 1) * (float) $entry->qty);
                $result = round((float) $layer->remaining_qty + $delta, 6);
                if ($layer->is_negative || $layer->batch_id !== null || $result < 0 || $result > (float) $layer->received_qty
                    || CostAdjustment::query()->where('negative_layer_id', $layerId)->orWhere('receipt_layer_id', $layerId)->exists()) {
                    throw new \DomainException('Original stock has been consumed or adjusted; reverse dependent movements first.');
                }
                $layer->remaining_qty = $result;
                $layer->save();
            }
            foreach (DocumentLine::query()->where('document_id', $original->id)->orderBy('line_no')->get() as $oldLine) {
                if ($oldLine->meta !== null && $oldLine->meta !== []) {
                    throw new \DomainException('Reversal of custom line metadata is not supported.');
                }
                $line = $this->line($document, new LineData(
                    (int) $oldLine->item_id,
                    (int) $oldLine->uom_id,
                    (int) $oldLine->warehouse_id,
                    (float) $oldLine->qty,
                    $oldLine->storage_location_id,
                    (float) $oldLine->qty_bonus,
                    batchId: $oldLine->batch_id,
                    serialId: $oldLine->serial_id,
                ), (int) $oldLine->line_no);
                $effects = $entries->where('document_line_id', $oldLine->id);
                $this->assertAvailable($line, (float) $effects->where('direction', 'in')->sum('qty'));
                foreach ($effects as $entry) {
                    $this->entry(
                        $line,
                        $entry->direction === 'in' ? 'out' : 'in',
                        (float) $entry->qty,
                        (float) $entry->unit_cost,
                        (int) $entry->cost_layer_id,
                        (float) $entry->qty_bonus,
                        (float) $entry->amount,
                    );
                }
            }
            $this->workflow->transition($original, DocumentStatus::REVERSED);

            return $this->finish($document);
        }, 3);
    }

    private function guard(): void
    {
        if (! config('inventory.policies.posting.enabled', true)
            || config('inventory.costing.scope') !== 'warehouse'
            || ! $this->accounting instanceof NullAccountingBridge || ! $this->approval instanceof NullApprovalBridge
            || config('inventory.accounting.enabled', false)) {
            throw new \DomainException('Standard transfer/reversal requires warehouse costing, enabled posting, and disabled bridges.');
        }
    }

    private function assertPosting(DocumentData $data): void
    {
        if (! $this->postingPolicy->evaluate('posting', $data)) {
            throw new \DomainException('Inventory posting is disabled by policy.');
        }
    }

    /** @param list<int> $ids */
    private function lockItems(array $ids): void
    {
        Item::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    private function validateKey(string $key): void
    {
        if (trim($key) === '' || strlen($key) > 128) {
            throw new \DomainException('An idempotency key of at most 128 bytes is required.');
        }
    }

    private function retry(Document $document, string $hash): Document
    {
        if (! hash_equals((string) $document->idempotency_hash, $hash)
            || config('inventory.idempotency.mode') !== 'return_existing') {
            throw new \DomainException('Duplicate operation or idempotency key has a different payload.');
        }

        return $document->load('lines');
    }

    private function line(Document $document, LineData $data, int $number): DocumentLine
    {
        $item = Item::query()->findOrFail($data->itemId);
        if (! $item->is_active || $item->item_type !== 'stock' || ! empty($item->tracking)
            || ($item->costing_method ?? config('inventory.costing.default_method')) !== 'fifo'
            || (int) $item->base_uom_id !== $data->uomId || $data->storageLocationId !== null
            || $data->batchId !== null || $data->serialId !== null) {
            throw new \DomainException('Operation supports active, untracked FIFO stock in base UOM at warehouse scope only.');
        }
        if (! DB::table('inv_organizations')->where('id', $data->warehouseId)->where('type', 'warehouse')->where('is_active', true)->exists()) {
            throw new \DomainException('An active warehouse is required.');
        }
        if (CostLayer::query()->where('item_id', $data->itemId)->where('scope_type', 'warehouse')
            ->where('scope_id', $data->warehouseId)->where('remaining_qty', '<', 0)->exists()) {
            throw new \DomainException('Settle negative cost layers before transfer or reversal.');
        }
        $line = new DocumentLine([
            'document_id' => $document->id, 'line_no' => $number, 'item_id' => $data->itemId,
            'uom_id' => $data->uomId, 'warehouse_id' => $data->warehouseId,
            'qty' => $data->qty, 'qty_bonus' => $data->qtyBonus,
        ]);
        if ($this->policies->resolve($line) !== null) {
            throw new \DomainException('Custom movement policies are not supported by standard transfer/reversal.');
        }
        $line->save();

        return $line;
    }

    private function assertAvailable(DocumentLine $line, float $qty): void
    {
        if ($qty > 0 && $this->availability->forItem((int) $line->item_id, (int) $line->warehouse_id)->availableQty() + 0.0000001 < $qty) {
            throw new \DomainException('Insufficient available stock for transfer or reversal.');
        }
    }

    private function entry(DocumentLine $line, string $direction, float $qty, float $cost, int $layerId, float $bonus = 0, ?float $amount = null): void
    {
        StockLedger::create([
            'document_line_id' => $line->id, 'item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id,
            'direction' => $direction, 'qty' => $qty, 'qty_bonus' => $bonus, 'unit_cost' => $cost,
            'amount' => $amount ?? round($qty * $cost, 6), 'cost_layer_id' => $layerId,
        ]);
    }

    private function finish(Document $document): Document
    {
        $this->workflow->transition($document, DocumentStatus::SUBMITTED);
        foreach ($document->lines()->get() as $line) {
            $this->cards->refresh($line);
        }
        $this->workflow->transition($document, DocumentStatus::POSTED);
        $document->forceFill(['posted_at' => now(), 'posting_started_at' => now(), 'posting_completed_at' => now(),
            'posting_marker' => 'document:' . $document->id])->save();
        DocumentPosted::dispatchAfterCommit($document->load('lines'));

        return $document;
    }
}
