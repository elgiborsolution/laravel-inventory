<?php

namespace ESolution\InventoryWms\Contracts;

use ESolution\InventoryWms\DTO\PickingRequest;

interface PickingStrategy
{
    /** @return list<\ESolution\InventoryWms\DTO\PickingSuggestion> */
    public function suggest(PickingRequest $request): array;
}
