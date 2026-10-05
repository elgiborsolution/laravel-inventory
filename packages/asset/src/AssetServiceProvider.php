<?php

namespace ESolution\InventoryAsset;

use ESolution\InventoryAsset\Console\Commands\DetectOverdueAssetsCommand;
use ESolution\InventoryAsset\Contracts\OverdueNotifier;
use ESolution\InventoryAsset\Services\AssetCheckoutService;
use ESolution\InventoryAsset\Services\AssetPreset;
use ESolution\InventoryAsset\Services\OverdueService;
use ESolution\InventoryAsset\Support\NullOverdueNotifier;
use Illuminate\Support\ServiceProvider;

final class AssetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inventory-asset.php', 'inventory-asset');
        $this->app->singleton(OverdueNotifier::class, NullOverdueNotifier::class);
        $this->app->singleton(AssetPreset::class);
        $this->app->singleton(AssetCheckoutService::class);
        $this->app->singleton(OverdueService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/inventory-asset.php' => config_path('inventory-asset.php'),
        ], 'inventory-asset-config');
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'inventory-asset-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([DetectOverdueAssetsCommand::class]);
        }
    }
}
