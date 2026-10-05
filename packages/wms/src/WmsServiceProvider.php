<?php

namespace ESolution\InventoryWms;

use ESolution\Inventory\Contracts\MovementPolicyRegistry;
use ESolution\Inventory\Events\DocumentPosted;
use ESolution\InventoryWms\Console\Commands\ScheduleReplenishmentCommand;
use ESolution\InventoryWms\Policies\Movement\CrossDockMovementPolicy;
use ESolution\InventoryWms\Services\PickingManager;
use ESolution\InventoryWms\Services\PutAwayManager;
use ESolution\InventoryWms\Services\TaskOrchestrator;
use ESolution\InventoryWms\Strategies\Picking\FefoPickingStrategy;
use ESolution\InventoryWms\Strategies\Picking\FifoPickingStrategy;
use ESolution\InventoryWms\Strategies\PutAway\DedicatedPutAwayStrategy;
use ESolution\InventoryWms\Strategies\PutAway\DynamicPutAwayStrategy;
use ESolution\InventoryWms\Strategies\PutAway\EmptyBinPutAwayStrategy;
use ESolution\InventoryWms\Strategies\PutAway\FixedPutAwayStrategy;
use ESolution\InventoryWms\Strategies\PutAway\NearestPutAwayStrategy;
use ESolution\InventoryWms\Strategies\PutAway\RandomPutAwayStrategy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class WmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inventory-wms.php', 'inventory-wms');

        $this->app->singleton(PutAwayManager::class, fn($app): PutAwayManager => new PutAwayManager($app, [
            'fixed' => FixedPutAwayStrategy::class,
            'dynamic' => DynamicPutAwayStrategy::class,
            'random' => RandomPutAwayStrategy::class,
            'dedicated' => DedicatedPutAwayStrategy::class,
            'nearest' => NearestPutAwayStrategy::class,
            'empty_bin' => EmptyBinPutAwayStrategy::class,
        ]));
        $this->app->singleton(PickingManager::class, fn($app): PickingManager => new PickingManager($app, [
            'fifo' => FifoPickingStrategy::class,
            'fefo' => FefoPickingStrategy::class,
        ]));
    }

    public function boot(MovementPolicyRegistry $movementPolicies): void
    {
        $movementPolicies->register('cross_dock', CrossDockMovementPolicy::class);
        Event::listen(DocumentPosted::class, [TaskOrchestrator::class, 'handle']);

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/inventory-wms.php' => config_path('inventory-wms.php'),
        ], 'inventory-wms-config');
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'inventory-wms-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ScheduleReplenishmentCommand::class]);
        }
    }
}
