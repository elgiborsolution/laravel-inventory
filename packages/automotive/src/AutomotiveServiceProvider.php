<?php

namespace ESolution\InventoryAutomotive;

use ESolution\Inventory\Bridges\NullAccountingBridge;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Support\DocumentTypeDefinition;
use ESolution\InventoryAutomotive\Services\AutomotivePreset;
use ESolution\InventoryAutomotive\Services\PartUsageReport;
use ESolution\InventoryAutomotive\Services\WorkOrderParts;
use Illuminate\Support\ServiceProvider;

final class AutomotiveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/inventory-automotive.php', 'inventory-automotive');
        $this->app->singleton(AutomotivePreset::class);
        $this->app->singleton(PartUsageReport::class);
        $this->app->singleton(WorkOrderParts::class);
    }
    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/inventory-automotive.php' => config_path('inventory-automotive.php')], 'inventory-automotive-config');
        $this->app->make(DocumentTypeRegistry::class)->register('work_order_parts_issue', new DocumentTypeDefinition(
            'out',
            beforePosting: static function (Document $document): void {
                if (config('inventory.accounting.enabled', false)
                    || config('inventory-automotive.accounting.enabled', false)
                    || ! app(AccountingBridge::class) instanceof NullAccountingBridge) {
                    throw new \DomainException('Automotive accounting is fail-closed: service codes are not verified.');
                }
                if (! $document->source_type || ! $document->source_id || ! $document->party_type || ! $document->party_id) {
                    throw new \DomainException('Automotive issue requires external Work Order and vehicle references.');
                }
            },
        ));
    }
}
