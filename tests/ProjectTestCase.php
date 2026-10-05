<?php

namespace ESolution\Inventory\Tests;

use ESolution\Inventory\InventoryServiceProvider;
use ESolution\InventoryProject\ProjectServiceProvider;

abstract class ProjectTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [InventoryServiceProvider::class, ProjectServiceProvider::class];
    }
}
