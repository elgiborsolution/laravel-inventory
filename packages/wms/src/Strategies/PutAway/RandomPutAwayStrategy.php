<?php

namespace ESolution\InventoryWms\Strategies\PutAway;

use ESolution\Inventory\Models\StorageLocation;
use ESolution\InventoryWms\Contracts\PutAwayStrategy;
use ESolution\InventoryWms\DTO\PutAwayRequest;
use ESolution\InventoryWms\Models\LocationProfile;
use ESolution\InventoryWms\Services\LocationCandidates;

final class RandomPutAwayStrategy implements PutAwayStrategy
{
    public function __construct(private readonly LocationCandidates $candidates) {}

    public function suggest(PutAwayRequest $request): StorageLocation
    {
        $seed = $request->deterministicKey !== ''
            ? $request->deterministicKey
            : "{$request->warehouseId}:{$request->itemId}:{$request->qty}";
        $profile = $this->candidates->forPutAway($request)
            ->filter(fn(LocationProfile $profile): bool => $profile->dedicated_item_id === null
                || (int) $profile->dedicated_item_id === $request->itemId)
            ->sortBy(fn(LocationProfile $profile): string => hash('sha256', $seed . ':' . $profile->storage_location_id))
            ->first();
        if (! $profile instanceof LocationProfile) {
            throw new \DomainException('No random put-away location is available.');
        }

        return $profile->storageLocation();
    }
}
