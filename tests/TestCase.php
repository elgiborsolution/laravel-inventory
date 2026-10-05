<?php

namespace ESolution\Inventory\Tests;

use ESolution\Inventory\InventoryServiceProvider;
use ESolution\Inventory\Tests\Concerns\BuildsInventoryScenario;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use BuildsInventoryScenario;

    protected function getPackageProviders($app): array
    {
        return [InventoryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $database = getenv('INVENTORY_TEST_DATABASE');
        if ($database !== false && $database !== '') {
            if (! preg_match('/^inventory_test_[a-z0-9_]+$/', $database)) {
                throw new \RuntimeException('Real-database tests require an isolated inventory_test_* database.');
            }
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver' => 'mysql', 'host' => getenv('INVENTORY_TEST_HOST') ?: '127.0.0.1',
                'port' => getenv('INVENTORY_TEST_PORT') ?: '3306', 'database' => $database,
                'username' => getenv('INVENTORY_TEST_USER') ?: 'root',
                'password' => getenv('INVENTORY_TEST_PASSWORD') ?: '',
                'prefix' => getenv('INVENTORY_TEST_PREFIX') ?: '',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
            ]);

            return;
        }
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
