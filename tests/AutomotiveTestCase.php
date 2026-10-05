<?php

namespace ESolution\Inventory\Tests;

use ESolution\Inventory\InventoryServiceProvider;
use ESolution\InventoryAutomotive\AutomotiveServiceProvider;

abstract class AutomotiveTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [InventoryServiceProvider::class, AutomotiveServiceProvider::class];
    }
}
