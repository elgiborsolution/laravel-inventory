<?php

namespace ESolution\InventoryProject;

use ESolution\InventoryProject\Services\ProjectAllocationReport;
use ESolution\InventoryProject\Services\ProjectAllocationService;
use Illuminate\Support\ServiceProvider;

final class ProjectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inventory-project.php', 'inventory-project');
        $this->app->singleton(ProjectAllocationService::class);
        $this->app->singleton(ProjectAllocationReport::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/inventory-project.php' => config_path('inventory-project.php'),
        ], 'inventory-project-config');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'inventory-project-migrations');
    }
}
