<?php

namespace ESolution\InventoryWms\Services;

use ESolution\InventoryWms\DTO\PutAwayRequest;
use ESolution\InventoryWms\Models\LocationProfile;
use Illuminate\Database\Eloquent\Collection;

final class LocationCandidates
{
    public function __construct(private readonly LocationInventory $inventory) {}

    /** @return Collection<int, LocationProfile> */
    public function forPutAway(PutAwayRequest $request): Collection
    {
        return LocationProfile::query()
            ->select('invw_location_profiles.*')
            ->join('inv_storage_locations', 'inv_storage_locations.id', '=', 'invw_location_profiles.storage_location_id')
            ->where('inv_storage_locations.organization_id', $request->warehouseId)
            ->where('inv_storage_locations.is_active', true)
            ->where('invw_location_profiles.put_away_enabled', true)
            ->when($request->zone !== null, fn($query) => $query->where('invw_location_profiles.zone', $request->zone))
            ->orderBy('invw_location_profiles.travel_sequence')
            ->orderBy('invw_location_profiles.storage_location_id')
            ->get()
            ->filter(fn(LocationProfile $profile): bool => $this->canFit($profile, $request->qty))
            ->values();
    }

    public function occupiedQty(LocationProfile $profile): float
    {
        return $this->inventory->quantity((int) $profile->storage_location_id);
    }

    public function itemQty(LocationProfile $profile, int $itemId): float
    {
        return $this->inventory->quantity((int) $profile->storage_location_id, $itemId);
    }

    private function canFit(LocationProfile $profile, float $qty): bool
    {
        return $profile->capacity_qty === null
            || $this->occupiedQty($profile) + $this->pendingInboundQty($profile) + $qty <= (float) $profile->capacity_qty;
    }

    private function pendingInboundQty(LocationProfile $profile): float
    {
        return (float) \ESolution\InventoryWms\Models\Task::query()
            ->where('to_location_id', $profile->storage_location_id)
            ->whereIn('type', ['put_away', 'cross_dock', 'replenishment'])
            ->whereIn('status', ['open', 'in_progress'])
            ->sum('qty');
    }
}
