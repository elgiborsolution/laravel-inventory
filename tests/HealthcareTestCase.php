<?php

namespace ESolution\Inventory\Tests;

use ESolution\Inventory\InventoryServiceProvider;
use ESolution\InventoryHealthcare\HealthcareServiceProvider;

abstract class HealthcareTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [InventoryServiceProvider::class, HealthcareServiceProvider::class];
    }
}
