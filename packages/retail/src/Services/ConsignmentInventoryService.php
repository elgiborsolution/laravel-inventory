<?php

namespace ESolution\InventoryRetail\Services;

use ESolution\Inventory\Models\StockLedger;
use ESolution\InventoryRetail\DTO\ConsignmentPosition;

final class ConsignmentInventoryService
{
    public function __construct(private readonly ConsignmentTermsService $terms) {}

    public function position(int $itemId, int $warehouseId, ?int $locationId = null): ConsignmentPosition
    {
        $term = $this->terms->resolve($itemId, $locationId)
            ?? throw new \DomainException('No active Consignment terms exist for this item and location.');
        $entries = StockLedger::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->when($locationId !== null, fn($query) => $query->where('storage_location_id', $locationId))
            ->get();
        $physical = 0.0;
        foreach ($entries as $entry) {
            $physical += ($entry->direction === 'in' ? 1 : -1) * (float) $entry->qty;
        }
        $referenceValue = $term->reference_unit_cost === null
            ? 0.0
            : $physical * (float) $term->reference_unit_cost;

        return new ConsignmentPosition($physical, $referenceValue, 0.0);
    }
}
