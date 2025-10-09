<?php

namespace ESolution\Inventory;

use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/inventory.php', 'inventory');

        $this->app->singleton(Services\InventoryManager::class, function($app){
            return new Services\InventoryManager($app);
        });
        $this->app->alias(Services\InventoryManager::class, 'inventory.manager');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/inventory.php' => config_path('inventory.php')],'inventory-config');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
