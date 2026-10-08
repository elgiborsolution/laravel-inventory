<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\Bridges\Support\TenantResolver;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\Contracts\MovementPolicyRegistry;
use ESolution\Inventory\Contracts\OwnershipNeutralMovementPolicy;
use ESolution\Inventory\DTO\AccountingPostingData;
use ESolution\Inventory\DTO\ApprovalContext;
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\DTO\ReservationConsumptionData;
use ESolution\Inventory\Enums\DocumentStatus;
use ESolution\Inventory\Events\DocumentPosted;
use ESolution\Inventory\Models\Batch;
use ESolution\Inventory\Models\CostAdjustment;
use ESolution\Inventory\Models\CostLayer;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\DocumentLine;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\Serial;
use ESolution\Inventory\Models\StockLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class PostingEngine
{
    public function __construct(
        private readonly DocumentTypeRegistry $documentTypes,
        private readonly WorkflowEngine $workflow,
        private readonly PolicyEngine $policies,
        private readonly ConfigurationDepthResolver $depth,
        private readonly StockCardManager $stockCards,
        private readonly AccountingBridge $accounting,
        private readonly ApprovalBridge $approval,
        private readonly TenantResolver $tenants,
        private readonly ReservationService $reservations,
        private readonly MovementPolicyRegistry $movementPolicies,
        private readonly TrackingPolicy $tracking,
    ) {}

    public function post(DocumentData $data): Document
    {
        $hash = $this->payloadHash($data);

        return DB::transaction(function () use ($data, $hash): Document {
            Item::query()->whereIn('id', array_map(fn(LineData $line): int => $line->itemId, $data->lines))
                ->orderBy('id')->lockForUpdate()->get();
            $existing = $this->findIdempotentDocument($data);
            if ($existing !== null) {
                if (! hash_equals((string) $existing->idempotency_hash, $hash)) {
                    throw new \DomainException('Idempotency key was already used with a different payload.');
                }

                if (config('inventory.idempotency.mode', 'return_existing') !== 'return_existing') {
                    throw new \DomainException('Duplicate document submission is not allowed.');
                }

                return $existing->load('lines');
            }

            if (! $this->policies->evaluate('posting', $data)) {
                throw new \DomainException('Inventory posting is disabled by policy.');
            }

            $definition = $this->documentTypes->get($data->type);
            $this->validateReservationConsumptions($data, $definition->direction);
            $meta = $data->meta;
            $meta['_accounting_context'] = [
                'additional_journal_lines' => $data->additionalJournalLines,
                'service_code' => $data->accountingServiceCode,
                'tenant_identity' => $data->tenantIdentity,
            ];
            $meta['_approval_context'] = [
                'action' => $data->approvalAction,
                'data' => $data->approvalData,
                'metadata' => $data->approvalMetadata,
                'tenant_identity' => $data->tenantIdentity,
            ];
            $meta['_reservation_consumptions'] = array_map(
                static fn(ReservationConsumptionData $consumption): array => get_object_vars($consumption),
                $data->reservationConsumptions,
            );
            $document = Document::create([
                'document_type' => $data->type,
                'organization_id' => $data->organizationId,
                'external_id' => $data->externalId,
                'idempotency_hash' => $hash,
                'party_type' => $data->partyType,
                'party_id' => $data->partyId,
                'source_type' => $data->sourceType,
                'source_id' => $data->sourceId,
                'trx_date' => $data->trxDate,
                'status' => DocumentStatus::DRAFT,
                'meta' => $meta,
            ]);

            foreach ($data->lines as $index => $lineData) {
                $this->createAndValidateLine($document, $lineData, $index + 1, $definition->direction);
            }

            if ($document->lines()->count() === 0) {
                throw new \DomainException('An inventory document must contain at least one line.');
            }

            $this->workflow->transition($document, DocumentStatus::SUBMITTED);

            $approvalData = array_merge([
                'document_id' => $document->getKey(),
                'document_type' => $document->document_type,
                'organization_id' => $document->organization_id,
                'trx_date' => $document->trx_date?->toDateString(),
                'party_type' => $document->party_type,
                'party_id' => $document->party_id,
                'source_type' => $document->source_type,
                'source_id' => $document->source_id,
            ], $data->approvalData);
            $detailData = DocumentLine::query()
                ->where('document_id', $document->getKey())
                ->orderBy('line_no')
                ->get()
                ->map(fn(DocumentLine $line): array => $line->toArray())
                ->all();
            $paused = $this->approval->checkAndSubmitIfRequired($document, new ApprovalContext(
                action: $data->approvalAction,
                data: $approvalData,
                detailData: $detailData,
                metadata: $data->approvalMetadata,
                tenantId: $this->tenants->resolve($data->tenantIdentity),
            ));
            if ($paused) {
                $document->refresh();
                if ($document->posting_completed_at !== null || $document->status === DocumentStatus::POSTED) {
                    return $document->load('lines');
                }
                if ($document->status === DocumentStatus::WAITING_APPROVAL) {
                    return $document->load('lines');
                }
                $this->workflow->transition($document, DocumentStatus::WAITING_APPROVAL);

                return $document->refresh()->load('lines');
            }

            $this->completePosting($document, $definition->direction, $definition->costing);

            return $document->refresh()->load('lines');
        }, 3);
    }

    public function resumeApproved(int $documentId): Document
    {
        return DB::transaction(function () use ($documentId): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($documentId);

            if ($document->posting_completed_at !== null || $document->status === DocumentStatus::POSTED) {
                return $document->load('lines');
            }

            if ($document->status !== DocumentStatus::APPROVED) {
                throw new \DomainException('Only an approved document can resume posting.');
            }

            $definition = $this->documentTypes->get($document->document_type);
            $this->completePosting($document, $definition->direction, $definition->costing);

            return $document->refresh()->load('lines');
        }, 3);
    }

    private function completePosting(Document $document, string $direction, bool $costing): void
    {
        $document = Document::query()->lockForUpdate()->findOrFail($document->getKey());
        if ($document->posting_completed_at !== null) {
            return;
        }

        $guard = $this->documentTypes->get($document->document_type)->beforePosting;
        if ($guard !== null) {
            $guard($document);
        }

        $document->forceFill([
            'posting_started_at' => now(),
            'posting_marker' => 'document:' . $document->getKey(),
        ])->save();

        $stockLines = [];
        // Serialize stock mutations with transfers/reversals, including empty scopes.
        Item::query()->whereIn('id', $document->lines()->select('item_id'))
            ->orderBy('id')->lockForUpdate()->get();
        $hasOwnershipNeutralReceipt = false;
        $hasOwnedReceipt = false;
        foreach (DocumentLine::query()->where('document_id', $document->getKey())->orderBy('line_no')->get() as $line) {
            $movementPolicy = $this->movementPolicies->resolve($line);
            $movementPolicy?->validate($line, $direction);
            if (Item::query()->findOrFail($line->item_id)->item_type !== 'stock' || $direction === 'none') {
                continue;
            }
            if ($direction === 'in') {
                $movementPolicy instanceof OwnershipNeutralMovementPolicy
                    ? $hasOwnershipNeutralReceipt = true
                    : $hasOwnedReceipt = true;
            }

            $direction === 'in'
                ? $this->receive($line, $costing)
                : $this->issue($line, $costing);

            $stockLines[] = $line;
        }

        $this->consumeLinkedReservations($document);

        if ($hasOwnershipNeutralReceipt && $hasOwnedReceipt) {
            throw new \DomainException('One receipt cannot mix owned and ownership-neutral stock lines.');
        }

        $context = (array) ($document->meta['_accounting_context'] ?? []);
        $totalCost = (float) StockLedger::query()
            ->whereIn('document_line_id', $document->lines()->select('id'))
            ->sum('amount');
        if (! $hasOwnershipNeutralReceipt) {
            $this->accounting->post($document, new AccountingPostingData(
                totalCost: $totalCost,
                direction: $direction,
                additionalJournalLines: (array) ($context['additional_journal_lines'] ?? []),
                serviceCode: isset($context['service_code']) ? (string) $context['service_code'] : null,
                tenantIdentity: $context['tenant_identity'] ?? null,
            ));
        }

        foreach ($stockLines as $line) {
            $this->stockCards->refresh($line);
        }

        $this->workflow->transition($document, DocumentStatus::POSTED);
        $document->forceFill([
            'posted_at' => now(),
            'posting_completed_at' => now(),
        ])->save();

        DocumentPosted::dispatchAfterCommit($document->refresh()->load('lines'));
    }

    private function createAndValidateLine(
        Document $document,
        LineData $data,
        int $lineNo,
        string $direction,
    ): DocumentLine {
        if ($data->qty <= 0 || $data->qtyBonus < 0) {
            throw new \DomainException("Line {$lineNo}: quantity must be positive and bonus cannot be negative.");
        }

        $item = Item::query()->findOrFail($data->itemId);
        if (! $item->is_active) {
            throw new \DomainException("Line {$lineNo}: item is inactive.");
        }

        if ($direction === 'in' && $item->item_type === 'stock' && ($data->unitCost === null || $data->unitCost < 0)) {
            throw new \DomainException("Line {$lineNo}: inbound stock requires a non-negative unit cost.");
        }

        $batch = $data->batchId === null ? null : Batch::query()->findOrFail($data->batchId);
        if ($batch !== null && (int) $batch->item_id !== $data->itemId) {
            throw new \DomainException("Line {$lineNo}: batch belongs to a different item.");
        }
        $this->tracking->validateLine($item, $batch, $direction, $data, $document->trx_date);

        $serial = $data->serialId === null ? null : Serial::query()->findOrFail($data->serialId);
        if ($serial !== null) {
            if ((int) $serial->item_id !== $data->itemId) {
                throw new \DomainException("Line {$lineNo}: serial belongs to a different item.");
            }
            if (($data->qty + $data->qtyBonus) !== 1.0) {
                throw new \DomainException("Line {$lineNo}: a serialized line must represent exactly one unit.");
            }
            if ($direction === 'out' && ($serial->status !== 'in_stock'
                || (int) $serial->warehouse_id !== $data->warehouseId
                || ($serial->storage_location_id !== null && (int) $serial->storage_location_id !== $data->storageLocationId))) {
                throw new \DomainException("Line {$lineNo}: serial is unavailable at the requested location.");
            }
        }

        $line = new DocumentLine([
            'line_no' => $lineNo,
            'item_id' => $data->itemId,
            'uom_id' => $data->uomId,
            'warehouse_id' => $data->warehouseId,
            'storage_location_id' => $data->storageLocationId,
            'qty' => $data->qty,
            'qty_bonus' => $data->qtyBonus,
            'unit_cost' => $data->unitCost,
            'batch_id' => $data->batchId,
            'serial_id' => $data->serialId,
            'meta' => $data->meta,
        ]);
        $line->document()->associate($document);
        $line->save();

        return $line;
    }

    private function validateReservationConsumptions(DocumentData $data, string $direction): void
    {
        if ($data->reservationConsumptions === []) {
            return;
        }
        if ($direction !== 'out') {
            throw new \DomainException('Reservation consumption can only be linked to an outbound document.');
        }
        if ($data->sourceId === null || $data->sourceId === '') {
            throw new \DomainException('A reservation fulfillment requires the document source reference.');
        }

        $keys = [];
        foreach ($data->reservationConsumptions as $consumption) {
            if (! $consumption instanceof ReservationConsumptionData) {
                throw new \InvalidArgumentException('Reservation consumptions must use ReservationConsumptionData.');
            }
            if ($consumption->lineNo < 1 || ! isset($data->lines[$consumption->lineNo - 1])) {
                throw new \DomainException('Reservation fulfillment references an unknown document line.');
            }
            if ($consumption->qty <= 0 || $consumption->idempotencyKey === '' || strlen($consumption->idempotencyKey) > 128) {
                throw new \DomainException('Reservation fulfillment requires a positive quantity and a valid idempotency key.');
            }

            $scopedKey = $consumption->reservationId . ':' . $consumption->idempotencyKey;
            if (isset($keys[$scopedKey])) {
                throw new \DomainException('A reservation fulfillment key may only appear once in a document payload.');
            }
            $keys[$scopedKey] = true;
        }
    }

    private function consumeLinkedReservations(Document $document): void
    {
        foreach ((array) ($document->meta['_reservation_consumptions'] ?? []) as $consumption) {
            $line = $document->lines()
                ->where('line_no', (int) ($consumption['lineNo'] ?? 0))
                ->firstOrFail();
            $this->reservations->consume(
                (int) ($consumption['reservationId'] ?? 0),
                (float) ($consumption['qty'] ?? 0),
                (string) ($consumption['idempotencyKey'] ?? ''),
                (int) $line->getKey(),
            );
        }
    }

    private function receive(DocumentLine $line, bool $costing): void
    {
        [$scopeType, $scopeId] = $this->depth->costingScope(
            (int) $line->warehouse_id,
            $line->storage_location_id === null ? null : (int) $line->storage_location_id,
        );
        $quantity = (float) $line->qty + (float) $line->qty_bonus;
        $purchaseAmount = (float) $line->qty * (float) $line->unit_cost;
        $blendedUnitCost = $costing ? $purchaseAmount / $quantity : 0.0;

        $layer = CostLayer::create([
            'item_id' => $line->item_id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'received_qty' => $quantity,
            'remaining_qty' => $quantity,
            'unit_cost' => $blendedUnitCost,
            'received_at' => Document::query()->findOrFail($line->document_id)->trx_date,
            'source_document_id' => $line->document_id,
            'batch_id' => $line->batch_id,
        ]);

        $this->settleNegativeLayers($layer);

        $this->appendLedger($line, 'in', $quantity, $blendedUnitCost, $layer->getKey(), (float) $line->qty_bonus);
    }

    private function settleNegativeLayers(CostLayer $receiptLayer): void
    {
        $available = (float) $receiptLayer->remaining_qty;
        $negativeLayers = CostLayer::query()
            ->where('item_id', $receiptLayer->item_id)
            ->where('scope_type', $receiptLayer->scope_type)
            ->where('scope_id', $receiptLayer->scope_id)
            ->where('is_negative', true)
            ->where('remaining_qty', '<', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($negativeLayers as $negativeLayer) {
            if ($available <= 0) {
                break;
            }

            $settled = min($available, abs((float) $negativeLayer->remaining_qty));
            $negativeLayer->remaining_qty = (float) $negativeLayer->remaining_qty + $settled;
            $negativeLayer->save();
            $available -= $settled;

            CostAdjustment::create([
                'item_id' => $receiptLayer->item_id,
                'scope_type' => $receiptLayer->scope_type,
                'scope_id' => $receiptLayer->scope_id,
                'negative_layer_id' => $negativeLayer->getKey(),
                'receipt_layer_id' => $receiptLayer->getKey(),
                'settled_qty' => $settled,
                'provisional_unit_cost' => $negativeLayer->unit_cost,
                'actual_unit_cost' => $receiptLayer->unit_cost,
                'amount_delta' => ((float) $receiptLayer->unit_cost - (float) $negativeLayer->unit_cost) * $settled,
            ]);
        }

        $receiptLayer->remaining_qty = $available;
        $receiptLayer->save();
    }

    private function issue(DocumentLine $line, bool $costing): void
    {
        [$scopeType, $scopeId] = $this->depth->costingScope(
            (int) $line->warehouse_id,
            $line->storage_location_id === null ? null : (int) $line->storage_location_id,
        );
        $remaining = (float) $line->qty + (float) $line->qty_bonus;
        $totalQuantity = $remaining;

        $item = Item::query()->findOrFail($line->item_id);
        $layersQuery = CostLayer::query()
            ->where('inv_cost_layers.item_id', $line->item_id)
            ->where('inv_cost_layers.scope_type', $scopeType)
            ->where('inv_cost_layers.scope_id', $scopeId)
            ->where('inv_cost_layers.remaining_qty', '>', 0)
            ->when($line->batch_id !== null, fn(Builder $query) => $query->where('inv_cost_layers.batch_id', $line->batch_id));
        $layers = $this->tracking
            ->prepareIssueLayers($layersQuery, $item, Document::query()->findOrFail($line->document_id)->trx_date)
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }

            $taken = min($remaining, (float) $layer->remaining_qty);
            $layer->remaining_qty = (float) $layer->remaining_qty - $taken;
            $layer->save();
            $bonus = (float) $line->qty_bonus * ($taken / $totalQuantity);
            $this->appendLedger($line, 'out', $taken, $costing ? (float) $layer->unit_cost : 0.0, $layer->getKey(), $bonus);
            $remaining -= $taken;
        }

        if ($remaining <= 0) {
            return;
        }

        if (config('inventory.policies.negative_stock.mode', 'block') !== 'allow') {
            throw new \DomainException('Insufficient stock for inventory issue.');
        }

        $lastKnownCost = CostLayer::query()
            ->where('item_id', $line->item_id)
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('unit_cost', '>', 0)
            ->latest('received_at')
            ->latest('id')
            ->value('unit_cost');
        if ($lastKnownCost === null) {
            throw new \DomainException('Negative stock requires a valid last-known cost.');
        }

        $negativeLayer = CostLayer::create([
            'item_id' => $line->item_id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'received_qty' => 0,
            'remaining_qty' => -$remaining,
            'unit_cost' => $lastKnownCost,
            'received_at' => now(),
            'source_document_id' => $line->document_id,
            'batch_id' => $line->batch_id,
            'is_negative' => true,
        ]);
        $bonus = (float) $line->qty_bonus * ($remaining / $totalQuantity);
        $this->appendLedger($line, 'out', $remaining, $costing ? (float) $lastKnownCost : 0.0, $negativeLayer->getKey(), $bonus);
    }

    private function appendLedger(
        DocumentLine $line,
        string $direction,
        float $quantity,
        float $unitCost,
        int $layerId,
        float $bonusQuantity = 0.0,
    ): void {
        StockLedger::create([
            'document_line_id' => $line->getKey(),
            'item_id' => $line->item_id,
            'warehouse_id' => $line->warehouse_id,
            'storage_location_id' => $line->storage_location_id,
            'direction' => $direction,
            'qty' => $quantity,
            'qty_bonus' => $bonusQuantity,
            'unit_cost' => $unitCost,
            'amount' => $quantity * $unitCost,
            'cost_layer_id' => $layerId,
        ]);
    }

    private function findIdempotentDocument(DocumentData $data): ?Document
    {
        if ($data->externalId === null) {
            return null;
        }

        return Document::query()
            ->where('organization_id', $data->organizationId)
            ->where('source_type', $data->sourceType)
            ->where('external_id', $data->externalId)
            ->lockForUpdate()
            ->first();
    }

    private function payloadHash(DocumentData $data): string
    {
        $payload = get_object_vars($data);
        $payload['lines'] = array_map(static fn(LineData $line): array => get_object_vars($line), $data->lines);
        $payload['reservationConsumptions'] = array_map(
            static fn(ReservationConsumptionData $consumption): array => get_object_vars($consumption),
            $data->reservationConsumptions,
        );

        return hash('sha256', (string) json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
