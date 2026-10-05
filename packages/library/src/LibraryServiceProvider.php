<?php

namespace ESolution\InventoryLibrary;

use ESolution\InventoryLibrary\Services\CirculationService;
use ESolution\InventoryLibrary\Services\LibraryPreset;
use Illuminate\Support\ServiceProvider;

final class LibraryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inventory-library.php', 'inventory-library');
        $this->app->singleton(LibraryPreset::class);
        $this->app->singleton(CirculationService::class);
    }
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([__DIR__ . '/../config/inventory-library.php' => config_path('inventory-library.php')], 'inventory-library-config');
    }
}
