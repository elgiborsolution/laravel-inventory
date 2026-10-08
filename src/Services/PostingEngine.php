<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\Bridges\Support\TenantResolver;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\Contracts\MovementPolicyRegistry;
use ESolution\Inventory\Contracts\OwnershipNeutralMovementPolicy;
use ESolution\Inventory\Drivers\Costing\MovingAverageDriver;
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

            if (in_array($data->type, ['stock_count', 'stock_opname'], true)) {
                $keys = [];
                foreach ($data->lines as $input) {
                    $key = $input->itemId . ':' . $input->warehouseId . ':' . ($input->storageLocationId ?? 'all');
                    if (isset($keys[$key])) {
                        throw new \DomainException('Stock count has duplicate item/location lines.');
                    }
                    $keys[$key] = true;
                    foreach ($data->lines as $other) {
                        if ($other !== $input && $other->itemId === $input->itemId && $other->warehouseId === $input->warehouseId
                            && $other->storageLocationId !== $input->storageLocationId
                            && ($other->storageLocationId === null || $input->storageLocationId === null)) {
                            throw new \DomainException('Stock count cannot mix warehouse totals and rack counts for one item.');
                        }
                    }
                }
            }
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
            $lineDirection = $direction;
            if (in_array($document->document_type, ['stock_count', 'stock_opname'], true)) {
                if ($movementPolicy !== null) {
                    throw new \DomainException('Stock count does not support custom movement policies.');
                }
                $lineDirection = $this->prepareStockCount($line);
            }
            $movementPolicy?->validate($line, $lineDirection);
            if (Item::query()->findOrFail($line->item_id)->item_type !== 'stock' || $lineDirection === 'none') {
                continue;
            }
            if ($lineDirection === 'in') {
                $movementPolicy instanceof OwnershipNeutralMovementPolicy
                    ? $hasOwnershipNeutralReceipt = true
                    : $hasOwnedReceipt = true;
            }

            $lineDirection === 'in'
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
        $isCount = in_array($document->document_type, ['stock_count', 'stock_opname'], true);
        if (! is_finite($data->qty) || ! is_finite($data->qtyBonus)
            || ($isCount ? $data->qty < 0 : $data->qty <= 0) || $data->qtyBonus < 0) {
            throw new \DomainException("Line {$lineNo}: quantity must be positive and bonus cannot be negative.");
        }

        $item = Item::query()->findOrFail($data->itemId);
        if (! $item->is_active) {
            throw new \DomainException("Line {$lineNo}: item is inactive.");
        }

        if ($isCount && ($item->item_type !== 'stock' || ! empty($item->tracking)
            || $data->batchId !== null || $data->serialId !== null || $data->qtyBonus !== 0.0
            || (int) $item->base_uom_id !== $data->uomId || $data->transactionPrice !== null)) {
            throw new \DomainException('Stock count requires untracked stock in base UOM without bonus or transaction pricing.');
        }
        $meta = $data->meta;
        if (isset($meta['_inventory_pricing']) || isset($meta['_stock_count'])) {
            throw new \DomainException('Reserved inventory metadata cannot be supplied by callers.');
        }
        $unitCost = $data->unitCost;
        if (! is_finite($data->discountPerUnit) || $data->discountPerUnit < 0
            || ($data->transactionPrice === null && $data->discountPerUnit !== 0.0)) {
            throw new \DomainException('Discount requires a transaction price and must be non-negative.');
        }
        if ($data->transactionPrice !== null) {
            if (! is_finite($data->transactionPrice) || $data->transactionPrice < $data->discountPerUnit) {
                throw new \DomainException('Transaction price must be finite and cover the discount.');
            }
            $net = round($data->transactionPrice - $data->discountPerUnit, 6);
            if (in_array($document->document_type, ['purchase', 'purchase_receipt'], true)) {
                if ($unitCost !== null && abs($unitCost - $net) > 0.000001) {
                    throw new \DomainException('Purchase unit cost must equal price less discount.');
                }
                $unitCost = $net;
            }
            $meta['_inventory_pricing'] = ['price' => $data->transactionPrice, 'discount' => $data->discountPerUnit, 'net' => $net];
        }
        if ($unitCost !== null && (! is_finite($unitCost) || $unitCost < 0)) {
            throw new \DomainException('Unit cost must be finite and non-negative.');
        }
        if ($direction === 'in' && $item->item_type === 'stock' && ($unitCost === null || $unitCost < 0)) {
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
            'unit_cost' => $unitCost,
            'batch_id' => $data->batchId,
            'serial_id' => $data->serialId,
            'meta' => $meta,
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

    private function usesMovingAverage(DocumentLine $line): bool
    {
        $item = Item::query()->findOrFail($line->item_id);

        return ($item->costing_method ?? config('inventory.costing.default_method')) === 'moving_average';
    }

    /** @return array{float, float} */
    private function balance(DocumentLine $line, bool $physical = false): array
    {
        [$scope, $id] = $this->depth->costingScope((int) $line->warehouse_id, $line->storage_location_id === null ? null : (int) $line->storage_location_id);
        $query = StockLedger::query()->where('item_id', $line->item_id)->where('warehouse_id', $line->warehouse_id);
        if ($scope === 'rack' || ($physical && $line->storage_location_id !== null)) {
            $query->where('storage_location_id', $line->storage_location_id);
        }
        $quantity = 0.0;
        $value = 0.0;
        foreach ($query->get() as $entry) {
            $sign = $entry->direction === 'in' ? 1 : -1;
            $quantity += $sign * (float) $entry->qty;
            $value += $sign * (float) $entry->amount;
        }
        $value -= (float) CostAdjustment::query()->where('item_id', $line->item_id)
            ->where('scope_type', $scope)->where('scope_id', $id)->sum('amount_delta');

        return [round($quantity, 6), round($value, 6)];
    }

    private function prepareStockCount(DocumentLine $line): string
    {
        [$systemQty] = $this->balance($line, true);
        $counted = (float) $line->qty;
        $difference = round($counted - $systemQty, 6);
        $meta = $line->meta ?? [];
        $meta['_stock_count'] = ['counted_qty' => $counted, 'system_qty' => $systemQty, 'difference' => $difference];
        $line->meta = $meta;
        $line->qty = abs($difference);
        if ($difference > 0 && $line->unit_cost === null) {
            [$qty, $value] = $this->balance($line);
            if ($qty <= 0) {
                throw new \DomainException('Stock count gain without an existing balance requires unitCost.');
            }
            $line->unit_cost = $value / $qty;
        }
        $line->save();

        return $difference > 0 ? 'in' : ($difference < 0 ? 'out' : 'none');
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

        if ($this->usesMovingAverage($line)) {
            [$currentQty, $currentValue] = $this->balance($line);
            if ($currentQty < 0 || $currentValue < 0) {
                throw new \DomainException('Settle negative stock before using moving average.');
            }
            $receipt = (new MovingAverageDriver())->receipt($currentQty, $currentValue, $quantity, $blendedUnitCost);
            $purchaseAmount = $receipt->amount - $currentValue;
        }
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

        $this->appendLedger($line, 'in', $quantity, $blendedUnitCost, $layer->getKey(), (float) $line->qty_bonus, round($costing ? $purchaseAmount : 0.0, 6));
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

        if ($line->storage_location_id !== null) {
            [$physicalQty] = $this->balance($line, true);
            if ($physicalQty + 0.0000001 < $remaining) {
                throw new \DomainException('Insufficient stock at the requested storage location.');
            }
        }
        $averageCost = null;
        $averageAmount = null;
        if ($costing && $this->usesMovingAverage($line)) {
            [$balanceQty, $balanceValue] = $this->balance($line);
            if ($balanceValue < 0) {
                throw new \DomainException('Moving average requires a non-negative inventory value.');
            }
            // A finite positive stock pool is required even when FIFO negative stock is enabled.
            $result = (new MovingAverageDriver())->issue([
                ['qty' => $balanceQty, 'unit_cost' => $balanceQty > 0 ? $balanceValue / $balanceQty : 0.0],
            ], $remaining);
            $averageCost = $result->unitCost;
            $averageAmount = round(min($result->amount, max(0.0, $balanceValue)), 6);
        }
        $issuedAmount = 0.0;
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
            $unitCost = $costing ? ($averageCost ?? (float) $layer->unit_cost) : 0.0;
            $amount = $averageAmount === null ? null : ($remaining - $taken < 0.0000001
                ? round($averageAmount - $issuedAmount, 6) : round($taken * $unitCost, 6));
            $this->appendLedger($line, 'out', $taken, $unitCost, $layer->getKey(), $bonus, $amount);
            $issuedAmount += $amount ?? $taken * $unitCost;
            $remaining = round($remaining - $taken, 6);
        }

        if ($remaining <= 0) {
            return;
        }

        if ($averageCost !== null || config('inventory.policies.negative_stock.mode', 'block') !== 'allow') {
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
        ?float $amount = null,
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
            'amount' => $amount ?? round($quantity * $unitCost, 6),
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
        $payload['lines'] = array_map(static fn(LineData $line): array => $line->jsonSerialize(), $data->lines);
        $payload['reservationConsumptions'] = array_map(
            static fn(ReservationConsumptionData $consumption): array => get_object_vars($consumption),
            $data->reservationConsumptions,
        );

        return hash('sha256', (string) json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
