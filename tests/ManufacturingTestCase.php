<?php

namespace ESolution\Inventory\Tests;

use ESolution\Inventory\InventoryServiceProvider;
use ESolution\InventoryManufacturing\ManufacturingServiceProvider;

abstract class ManufacturingTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [InventoryServiceProvider::class, ManufacturingServiceProvider::class];
    }
}
