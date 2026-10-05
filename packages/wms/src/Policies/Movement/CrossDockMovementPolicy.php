<?php

namespace ESolution\InventoryWms\Policies\Movement;

use ESolution\Inventory\Contracts\MovementPolicy;
use ESolution\Inventory\Models\DocumentLine;
use ESolution\InventoryWms\Models\CrossDockRoute;

final class CrossDockMovementPolicy implements MovementPolicy
{
    public function name(): string
    {
        return 'cross_dock';
    }

    public function validate(DocumentLine $line, string $direction): void
    {
        if ($direction !== 'in') {
            throw new \DomainException('Cross-docking is only valid for inbound inventory.');
        }

        $exists = CrossDockRoute::query()
            ->join('inv_storage_locations', 'inv_storage_locations.id', '=', 'invw_cross_dock_routes.staging_location_id')
            ->where('invw_cross_dock_routes.warehouse_id', $line->warehouse_id)
            ->whereColumn('inv_storage_locations.organization_id', 'invw_cross_dock_routes.warehouse_id')
            ->where('inv_storage_locations.is_active', true)
            ->where('invw_cross_dock_routes.is_active', true)
            ->where(fn($query) => $query->where('invw_cross_dock_routes.item_id', $line->item_id)
                ->orWhereNull('invw_cross_dock_routes.item_id'))
            ->exists();
        if (! $exists) {
            throw new \DomainException('Cross-docking requires an active staging route.');
        }
    }
}
