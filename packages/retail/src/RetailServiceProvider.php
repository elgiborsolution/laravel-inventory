<?php

namespace ESolution\InventoryRetail;

use ESolution\Inventory\Contracts\MovementPolicyRegistry;
use ESolution\Inventory\Events\DocumentPosted;
use ESolution\InventoryRetail\Console\Commands\SettleConsignmentCommand;
use ESolution\InventoryRetail\Policies\Movement\ConsignmentMovementPolicy;
use ESolution\InventoryRetail\Services\ConsignmentTermsService;
use ESolution\InventoryRetail\Services\SettlementRecorder;
use ESolution\InventoryRetail\Services\VariantMatrixGenerator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class RetailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inventory-retail.php', 'inventory-retail');

        $this->app->bind(ConsignmentMovementPolicy::class);
        $this->app->singleton(ConsignmentTermsService::class);
        $this->app->singleton(VariantMatrixGenerator::class);
        $this->app->singleton(SettlementRecorder::class);
    }

    public function boot(MovementPolicyRegistry $movementPolicies): void
    {
        $movementPolicies->register('consignment', ConsignmentMovementPolicy::class);
        Event::listen(DocumentPosted::class, [SettlementRecorder::class, 'handle']);

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/inventory-retail.php' => config_path('inventory-retail.php'),
        ], 'inventory-retail-config');
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'inventory-retail-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([SettleConsignmentCommand::class]);
        }
    }
}
