<?php

namespace ESolution\InventoryWms\Strategies\PutAway;

use ESolution\Inventory\Models\StorageLocation;
use ESolution\InventoryWms\Contracts\PutAwayStrategy;
use ESolution\InventoryWms\DTO\PutAwayRequest;
use ESolution\InventoryWms\Models\LocationProfile;
use ESolution\InventoryWms\Models\PutAwayRule;
use ESolution\InventoryWms\Services\LocationCandidates;

final class FixedPutAwayStrategy implements PutAwayStrategy
{
    public function __construct(private readonly LocationCandidates $candidates) {}

    public function suggest(PutAwayRequest $request): StorageLocation
    {
        $rule = PutAwayRule::query()
            ->where('warehouse_id', $request->warehouseId)
            ->where('strategy', 'fixed')
            ->where('is_active', true)
            ->where(fn($query) => $query->where('item_id', $request->itemId)->orWhereNull('item_id'))
            ->orderByRaw('CASE WHEN item_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
        if ($rule?->fixed_location_id === null) {
            throw new \DomainException('No fixed put-away location is configured.');
        }

        $profile = $this->candidates->forPutAway($request)
            ->firstWhere('storage_location_id', (int) $rule->fixed_location_id);
        if (! $profile instanceof LocationProfile) {
            throw new \DomainException('The fixed put-away location is unavailable or lacks capacity.');
        }

        return $profile->storageLocation();
    }
}
