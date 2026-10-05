<?php

namespace ESolution\InventoryWms\Strategies\PutAway;

use ESolution\Inventory\Models\StorageLocation;
use ESolution\InventoryWms\Contracts\PutAwayStrategy;
use ESolution\InventoryWms\DTO\PutAwayRequest;
use ESolution\InventoryWms\Models\LocationProfile;
use ESolution\InventoryWms\Services\LocationCandidates;

final class EmptyBinPutAwayStrategy implements PutAwayStrategy
{
    public function __construct(private readonly LocationCandidates $candidates) {}

    public function suggest(PutAwayRequest $request): StorageLocation
    {
        $profile = $this->candidates->forPutAway($request)
            ->first(fn(LocationProfile $profile): bool => $this->candidates->occupiedQty($profile) === 0.0
                && ($profile->dedicated_item_id === null || (int) $profile->dedicated_item_id === $request->itemId));
        if (! $profile instanceof LocationProfile) {
            throw new \DomainException('No empty put-away bin is available.');
        }

        return $profile->storageLocation();
    }
}
