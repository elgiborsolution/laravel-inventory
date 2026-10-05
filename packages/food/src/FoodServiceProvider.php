<?php

namespace ESolution\InventoryFood;

use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\Services\WorkflowEngine;
use ESolution\Inventory\Support\DocumentTypeDefinition;
use ESolution\InventoryFood\Services\FoodAccountingGuard;
use ESolution\InventoryFood\Services\FoodPreset;
use ESolution\InventoryFood\Services\MadeToOrderTrigger;
use ESolution\InventoryFood\Services\RecipeBatchService;
use ESolution\InventoryFood\Services\RecipeService;
use Illuminate\Support\ServiceProvider;

final class FoodServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inventory-food.php', 'inventory-food');
        $this->app->singleton(FoodAccountingGuard::class);
        $this->app->singleton(FoodPreset::class);
        $this->app->singleton(RecipeService::class);
        $this->app->singleton(RecipeBatchService::class);
        $this->app->singleton(MadeToOrderTrigger::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->publishes([
            __DIR__ . '/../config/inventory-food.php' => config_path('inventory-food.php'),
        ], 'inventory-food-config');
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'inventory-food-migrations');

        $registry = $this->app->make(DocumentTypeRegistry::class);
        $registry->register('recipe_consumption', new DocumentTypeDefinition('out'));
        $registry->register('recipe_receipt', new DocumentTypeDefinition('in'));
        $mtoDocumentType = (string) config('inventory-food.mto.document_type', 'food_order');
        if (! $registry->has($mtoDocumentType)) {
            $registry->register($mtoDocumentType, new DocumentTypeDefinition('none', costing: false));
        }

        $trigger = $this->app->make(MadeToOrderTrigger::class);
        $this->app->make(WorkflowEngine::class)->onTransition(
            $mtoDocumentType,
            static function ($document, string $from, string $to) use ($trigger): void {
                $trigger->handle($document, $to);
            },
        );
    }
}
