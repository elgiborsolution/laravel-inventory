<?php

namespace ESolution\Inventory\Facades;

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\ReversalRequest;
use ESolution\Inventory\DTO\StockAvailability;
use ESolution\Inventory\DTO\TransferData;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\Reservation;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Document post(DocumentData $document)
 * @method static Document transfer(TransferData $data)
 * @method static Document reverse(ReversalRequest $request)
 * @method static Document resumeApproved(int $documentId)
 * @method static Reservation reserve(int $itemId, float $qty, int $warehouseId, string $sourceType, string $sourceId)
 * @method static Reservation release(int $reservationId, ?float $qty = null)
 * @method static Reservation consume(int $reservationId, float $qty, string $idempotencyKey, ?int $documentLineId = null)
 * @method static array stockCard(int $itemId, int $warehouseId, ?int $storageLocationId = null)
 * @method static StockAvailability availability(int $itemId, int $warehouseId)
 */
final class Inventory extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'inventory.manager';
    }
}
