<?php

namespace ESolution\Inventory\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed post(\ESolution\Inventory\DTO\DocumentData $doc)
 * @method static mixed transferRack(array $params)
 * @method static mixed transferWarehouse(array $params)
 * @method static mixed transferBranch(array $params)
 */
class Inventory extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'inventory.manager';
    }
}
