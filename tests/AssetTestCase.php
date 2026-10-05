<?php

namespace ESolution\Inventory\Tests;

use ESolution\Inventory\InventoryServiceProvider;
use ESolution\InventoryAsset\AssetServiceProvider;

abstract class AssetTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [InventoryServiceProvider::class, AssetServiceProvider::class];
    }
}
