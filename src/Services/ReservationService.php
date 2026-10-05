<?php

namespace ESolution\Inventory\Services;

use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\DocumentLine;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\Reservation;
use ESolution\Inventory\Models\ReservationConsumption;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    public function __construct(
        private readonly StockAvailabilityService $availability,
        private readonly DocumentTypeRegistry $documentTypes,
    ) {}

    public function reserve(
        int $itemId,
        float $qty,
        int $warehouseId,
        string $sourceType,
        string $sourceId,
    ): Reservation {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Reservation quantity must be positive.');
        }
        if ($sourceType === '' || $sourceId === '') {
            throw new \InvalidArgumentException('Reservation source type and source ID are required.');
        }
        if (! (bool) config('inventory.policies.reservation.enabled', true)) {
            throw new \DomainException('Inventory reservation is disabled by policy.');
        }

        return DB::transaction(function () use ($itemId, $qty, $warehouseId, $sourceType, $sourceId): Reservation {
            // The item row is the portable lock anchor for reservation availability.
            // It serializes overlapping reservation decisions even when no reservation row exists yet.
            Item::query()->lockForUpdate()->findOrFail($itemId);

            $appliesTo = (array) config('inventory.policies.negative_stock.applies_to', ['goods_issue']);
            if (config('inventory.policies.negative_stock.mode', 'block') === 'block'
                && in_array('reservation', $appliesTo, true)
                && $qty > $this->availability->forItem($itemId, $warehouseId)->availableQty()) {
                throw new \DomainException('Insufficient available stock for reservation.');
            }

            return Reservation::create([
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reserved_qty' => $qty,
                'status' => 'active',
            ]);
        }, 3);
    }

    public function release(int $id, ?float $qty = null): Reservation
    {
        return DB::transaction(function () use ($id, $qty): Reservation {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($id);
            $amount = $qty ?? $reservation->remaining_qty;
            if ($amount <= 0 || $amount > $reservation->remaining_qty) {
                throw new \DomainException('Invalid reservation release quantity.');
            }

            $reservation->released_qty = (float) $reservation->released_qty + $amount;
            $reservation->status = $reservation->remaining_qty - $amount <= 0 ? 'released' : 'active';
            $reservation->save();

            return $reservation->refresh();
        }, 3);
    }

    public function consume(int $id, float $qty, string $key, ?int $lineId = null): Reservation
    {
        return DB::transaction(function () use ($id, $qty, $key, $lineId): Reservation {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($id);
            $this->validateConsumptionInput($qty, $key);

            $existing = ReservationConsumption::query()
                ->where('reservation_id', $id)
                ->where('idempotency_key', $key)
                ->first();
            if ($existing !== null) {
                if ((float) $existing->qty !== $qty
                    || ($existing->document_line_id === null ? null : (int) $existing->document_line_id) !== $lineId) {
                    throw new \DomainException('Reservation fulfillment key was reused with a different payload.');
                }

                return $reservation;
            }

            $line = $lineId === null ? null : DocumentLine::query()->findOrFail($lineId);
            if ($line !== null) {
                $this->validateDocumentLink($reservation, $line, $qty);
            }
            if ($qty > $reservation->remaining_qty) {
                throw new \DomainException('Reservation consumption exceeds remaining quantity.');
            }

            ReservationConsumption::create([
                'reservation_id' => $id,
                'document_line_id' => $lineId,
                'idempotency_key' => $key,
                'qty' => $qty,
                'created_at' => now(),
            ]);
            $reservation->consumed_qty = (float) $reservation->consumed_qty + $qty;
            $reservation->status = $reservation->remaining_qty - $qty <= 0 ? 'consumed' : 'active';
            $reservation->save();

            return $reservation->refresh();
        }, 3);
    }

    private function validateConsumptionInput(float $qty, string $key): void
    {
        if ($qty <= 0) {
            throw new \DomainException('Reservation consumption quantity must be positive.');
        }
        if ($key === '' || strlen($key) > 128) {
            throw new \DomainException('Reservation fulfillment idempotency key must contain 1 to 128 characters.');
        }
    }

    private function validateDocumentLink(Reservation $reservation, DocumentLine $line, float $qty): void
    {
        if ((int) $reservation->item_id !== (int) $line->item_id
            || (int) $reservation->warehouse_id !== (int) $line->warehouse_id) {
            throw new \DomainException('Reservation item and warehouse must match the Goods Issue line.');
        }

        $document = Document::query()->find($line->document_id);
        if ($document === null
            || $reservation->source_type !== $document->source_type
            || (string) $reservation->source_id !== (string) $document->source_id) {
            throw new \DomainException('Reservation source must match the Goods Issue source reference.');
        }
        if ($this->documentTypes->get((string) $document->document_type)->direction !== 'out') {
            throw new \DomainException('Reservation consumption must link to an outbound document line.');
        }

        $alreadyLinked = (float) ReservationConsumption::query()
            ->where('document_line_id', $line->getKey())
            ->sum('qty');
        if ($alreadyLinked + $qty > (float) $line->qty + (float) $line->qty_bonus) {
            throw new \DomainException('Reservation consumption exceeds the linked document line quantity.');
        }
    }
}
