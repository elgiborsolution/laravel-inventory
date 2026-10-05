<?php

namespace ESolution\InventoryWms\Strategies\PutAway;

use ESolution\Inventory\Models\StorageLocation;
use ESolution\InventoryWms\Contracts\PutAwayStrategy;
use ESolution\InventoryWms\DTO\PutAwayRequest;
use ESolution\InventoryWms\Models\LocationProfile;
use ESolution\InventoryWms\Services\LocationCandidates;

final class DynamicPutAwayStrategy implements PutAwayStrategy
{
    public function __construct(private readonly LocationCandidates $candidates) {}

    public function suggest(PutAwayRequest $request): StorageLocation
    {
        $profiles = $this->candidates->forPutAway($request)
            ->filter(fn(LocationProfile $profile): bool => $profile->dedicated_item_id === null
                || (int) $profile->dedicated_item_id === $request->itemId)
            ->sortBy(fn(LocationProfile $profile): array => [
                $this->candidates->itemQty($profile, $request->itemId) > 0 ? 0 : 1,
                (int) $profile->travel_sequence,
                (int) $profile->storage_location_id,
            ]);

        $profile = $profiles->first();
        if (! $profile instanceof LocationProfile) {
            throw new \DomainException('No dynamic put-away location is available.');
        }

        return $profile->storageLocation();
    }
}
