<?php

namespace ESolution\Inventory\Tests;

use ESolution\Inventory\InventoryServiceProvider;
use ESolution\InventoryWms\WmsServiceProvider;

abstract class WmsTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [InventoryServiceProvider::class, WmsServiceProvider::class];
    }
}
