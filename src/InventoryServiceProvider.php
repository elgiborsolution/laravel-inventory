<?php

namespace ESolution\Inventory;

use ESolution\Inventory\Bridges\ExternalAccountingBridge;
use ESolution\Inventory\Bridges\ExternalApprovalBridge;
use ESolution\Inventory\Bridges\LaravelAccountingJournalGateway;
use ESolution\Inventory\Bridges\LaravelApprovalWorkflowGateway;
use ESolution\Inventory\Bridges\NullAccountingBridge;
use ESolution\Inventory\Bridges\NullApprovalBridge;
use ESolution\Inventory\Commands\ValidateAccountingCommand;
use ESolution\Inventory\Commands\ValidateApprovalCommand;
use ESolution\Inventory\Commands\ValidateConfigurationCommand;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\AccountingJournalGateway;
use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\Contracts\ApprovalWorkflowGateway;
use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\Contracts\MovementPolicyRegistry;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Observers\DocumentApprovalObserver;
use ESolution\Inventory\Services\ConfigurationDepthResolver;
use ESolution\Inventory\Services\InMemoryDocumentTypeRegistry;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\Inventory\Services\MovementPolicyManager;
use ESolution\Inventory\Services\PolicyEngine;
use ESolution\Inventory\Services\PostingEngine;
use ESolution\Inventory\Services\ReservationService;
use ESolution\Inventory\Services\ResumeApprovedDocument;
use ESolution\Inventory\Services\StockAvailabilityService;
use ESolution\Inventory\Services\StockCardManager;
use ESolution\Inventory\Services\TrackingPolicy;
use ESolution\Inventory\Services\WorkflowEngine;
use ESolution\Inventory\Support\ApprovalPackageInspector;
use ESolution\Inventory\Support\DocumentTypeDefinition;
use Illuminate\Support\ServiceProvider;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inventory.php', 'inventory');

        $this->app->singleton(AccountingJournalGateway::class, LaravelAccountingJournalGateway::class);
        $this->app->singleton(AccountingBridge::class, function ($app): AccountingBridge {
            $enabled = (bool) config('inventory.accounting.enabled', false);
            $installed = class_exists('ESolution\\LaravelAccounting\\Services\\JournalService');

            return $enabled && $installed
                ? $app->make(ExternalAccountingBridge::class)
                : $app->make(NullAccountingBridge::class);
        });
        $this->app->singleton(ApprovalWorkflowGateway::class, LaravelApprovalWorkflowGateway::class);
        $this->app->singleton(ApprovalBridge::class, function ($app): ApprovalBridge {
            $installed = $app->make(ApprovalPackageInspector::class)->installed();

            return $installed
                ? $app->make(ExternalApprovalBridge::class)
                : $app->make(NullApprovalBridge::class);
        });

        $this->app->singleton(DocumentTypeRegistry::class, function (): DocumentTypeRegistry {
            $registry = new InMemoryDocumentTypeRegistry();

            foreach ([
                'purchase_receipt', 'customer_return', 'positive_adjustment', 'production_receipt',
                'recipe_receipt', 'purchase', 'sales_return',
            ] as $type) {
                $registry->register($type, new DocumentTypeDefinition('in'));
            }

            foreach ([
                'goods_issue', 'sales_delivery', 'supplier_return', 'negative_adjustment', 'scrap',
                'production_consumption', 'recipe_consumption', 'work_order_parts_issue', 'sale',
                'purchase_return',
            ] as $type) {
                $registry->register($type, new DocumentTypeDefinition('out'));
            }

            foreach (['stock_count', 'stock_opname'] as $type) {
                $registry->register($type, new DocumentTypeDefinition('none', costing: false));
            }

            return $registry;
        });
        $this->app->singleton(MovementPolicyRegistry::class, MovementPolicyManager::class);

        $this->app->singleton(ConfigurationDepthResolver::class);
        $this->app->singleton(PolicyEngine::class);
        $this->app->singleton(WorkflowEngine::class);
        $this->app->singleton(StockCardManager::class);
        $this->app->singleton(TrackingPolicy::class);
        $this->app->singleton(StockAvailabilityService::class);
        $this->app->singleton(ReservationService::class);
        $this->app->singleton(PostingEngine::class);
        $this->app->singleton(ResumeApprovedDocument::class);
        $this->app->singleton(InventoryManager::class);
        $this->app->alias(InventoryManager::class, 'inventory.manager');
    }

    public function boot(): void
    {
        Document::observe(DocumentApprovalObserver::class);

        $this->publishes([
            __DIR__ . '/../config/inventory.php' => config_path('inventory.php'),
        ], ['inventory-config', 'inventory-core-config']);

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'inventory-migrations');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ValidateAccountingCommand::class,
                ValidateApprovalCommand::class,
                ValidateConfigurationCommand::class,
            ]);
        }
    }
}
