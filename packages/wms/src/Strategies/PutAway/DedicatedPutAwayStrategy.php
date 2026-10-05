<?php

namespace ESolution\InventoryWms\Strategies\PutAway;

use ESolution\Inventory\Models\StorageLocation;
use ESolution\InventoryWms\Contracts\PutAwayStrategy;
use ESolution\InventoryWms\DTO\PutAwayRequest;
use ESolution\InventoryWms\Models\LocationProfile;
use ESolution\InventoryWms\Services\LocationCandidates;

final class DedicatedPutAwayStrategy implements PutAwayStrategy
{
    public function __construct(private readonly LocationCandidates $candidates) {}

    public function suggest(PutAwayRequest $request): StorageLocation
    {
        $profile = $this->candidates->forPutAway($request)
            ->first(fn(LocationProfile $profile): bool => (int) $profile->dedicated_item_id === $request->itemId);
        if (! $profile instanceof LocationProfile) {
            throw new \DomainException('No dedicated put-away location is available for the item.');
        }

        return $profile->storageLocation();
    }
}
