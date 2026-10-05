<?php

use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\Services\InventoryManager;
use Illuminate\Support\Facades\Schema;

it('boots the package and merges its configuration', function (): void {
    expect(config('inventory.costing.default_method'))->toBe('fifo')
        ->and(app()->bound(InventoryManager::class))->toBeTrue()
        ->and(app()->bound(DocumentTypeRegistry::class))->toBeTrue()
        ->and(app()->bound('inventory.manager'))->toBeTrue();
});

it('keeps published configuration serializable for config cache', function (): void {
    $configuration = require dirname(__DIR__, 2) . '/config/inventory.php';

    expect($configuration)->toBeArray()
        ->and(serialize($configuration))->toBeString();
});

it('installs the current reference migrations on a clean database', function (): void {
    if (! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('The local PHP runtime does not have pdo_sqlite; CI runs this test with SQLite enabled.');
    }

    $this->artisan('migrate', ['--database' => 'testing'])->assertSuccessful();

    expect(Schema::hasTable('inv_documents'))->toBeTrue()
        ->and(Schema::hasTable('inv_stock_ledgers'))->toBeTrue()
        ->and(Schema::hasTable('inv_cost_layers'))->toBeTrue()
        ->and(Schema::hasTable('inv_reservations'))->toBeTrue();
});

it('exposes a successful configuration validation command', function (): void {
    $this->artisan('inventory:validate-config')
        ->expectsOutput('Inventory configuration is valid.')
        ->assertSuccessful();
});
