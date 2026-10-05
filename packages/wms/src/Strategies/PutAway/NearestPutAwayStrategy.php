<?php

namespace ESolution\InventoryWms\Strategies\PutAway;

use ESolution\Inventory\Models\StorageLocation;
use ESolution\InventoryWms\Contracts\PutAwayStrategy;
use ESolution\InventoryWms\DTO\PutAwayRequest;
use ESolution\InventoryWms\Models\LocationProfile;
use ESolution\InventoryWms\Services\LocationCandidates;

final class NearestPutAwayStrategy implements PutAwayStrategy
{
    public function __construct(private readonly LocationCandidates $candidates) {}

    public function suggest(PutAwayRequest $request): StorageLocation
    {
        $origin = $request->fromLocationId === null
            ? 0
            : (int) LocationProfile::query()->where('storage_location_id', $request->fromLocationId)->value('travel_sequence');
        $profile = $this->candidates->forPutAway($request)
            ->filter(fn(LocationProfile $profile): bool => $profile->dedicated_item_id === null
                || (int) $profile->dedicated_item_id === $request->itemId)
            ->sortBy(fn(LocationProfile $profile): array => [
                abs((int) $profile->travel_sequence - $origin),
                (int) $profile->travel_sequence,
                (int) $profile->storage_location_id,
            ])->first();
        if (! $profile instanceof LocationProfile) {
            throw new \DomainException('No nearest put-away location is available.');
        }

        return $profile->storageLocation();
    }
}
