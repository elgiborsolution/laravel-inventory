<?php

namespace ESolution\Inventory\Tests;

use ESolution\Inventory\InventoryServiceProvider;
use ESolution\InventoryRetail\RetailServiceProvider;

abstract class RetailTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [InventoryServiceProvider::class, RetailServiceProvider::class];
    }
}
