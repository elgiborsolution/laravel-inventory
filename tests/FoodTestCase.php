<?php

namespace ESolution\Inventory\Tests;

use ESolution\Inventory\InventoryServiceProvider;
use ESolution\InventoryFood\FoodServiceProvider;

abstract class FoodTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [InventoryServiceProvider::class, FoodServiceProvider::class];
    }
}
