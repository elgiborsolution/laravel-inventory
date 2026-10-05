<?php

namespace ESolution\InventoryManufacturing;

use ESolution\InventoryManufacturing\Services\BomService;
use ESolution\InventoryManufacturing\Services\ManufacturingAccountingGuard;
use ESolution\InventoryManufacturing\Services\ProductionOrderService;
use Illuminate\Support\ServiceProvider;

final class ManufacturingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/inventory-manufacturing.php',
            'inventory-manufacturing',
        );

        $this->app->singleton(ManufacturingAccountingGuard::class);
        $this->app->singleton(BomService::class);
        $this->app->singleton(ProductionOrderService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/inventory-manufacturing.php' => config_path('inventory-manufacturing.php'),
        ], 'inventory-manufacturing-config');
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'inventory-manufacturing-migrations');
    }
}
