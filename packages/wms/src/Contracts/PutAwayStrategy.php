<?php

namespace ESolution\InventoryWms\Contracts;

use ESolution\Inventory\Models\StorageLocation;
use ESolution\InventoryWms\DTO\PutAwayRequest;

interface PutAwayStrategy
{
    public function suggest(PutAwayRequest $request): StorageLocation;
}
