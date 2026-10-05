<?php

namespace ESolution\InventoryHealthcare;

use ESolution\InventoryHealthcare\Services\HealthcarePreset;
use ESolution\InventoryHealthcare\Services\RecallService;
use Illuminate\Support\ServiceProvider;

final class HealthcareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inventory-healthcare.php', 'inventory-healthcare');
        $this->app->singleton(HealthcarePreset::class);
        $this->app->singleton(RecallService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/inventory-healthcare.php' => config_path('inventory-healthcare.php'),
        ], 'inventory-healthcare-config');
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'inventory-healthcare-migrations');
    }
}
