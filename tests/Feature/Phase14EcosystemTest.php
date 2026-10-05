<?php

use ESolution\Inventory\Bridges\NullAccountingBridge;
use ESolution\Inventory\Bridges\NullApprovalBridge;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\Services\InventoryManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

dataset('ecosystem packages', [
    'Core' => [[]],
    'Retail' => [['retail']],
    'Manufacturing' => [['manufacturing']],
    'Healthcare' => [['healthcare']],
    'WMS' => [['wms']],
    'Food' => [['food']],
    'Asset' => [['asset']],
    'Project' => [['project']],
    'Automotive' => [['automotive']],
    'Library' => [['library']],
    'Retail Manufacturing' => [['retail', 'manufacturing']],
    'Healthcare Retail' => [['healthcare', 'retail']],
    'WMS Healthcare' => [['wms', 'healthcare']],
    'Food WMS' => [['food', 'wms']],
    'Asset Project' => [['asset', 'project']],
    'Library Retail' => [['library', 'retail']],
    'Full catalog' => [['retail', 'manufacturing', 'healthcare', 'wms', 'food', 'asset', 'project', 'automotive', 'library']],
]);

test('Phase14 empty install coexistence posting retry and rollback smoke', function (array $packages): void {
    $root = dirname(__DIR__, 2);
    foreach ($packages as $package) {
        $manifest = json_decode(file_get_contents($root . '/packages/' . $package . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['extra']['laravel']['providers'] as $provider) {
            app()->register($provider);
            foreach (ServiceProvider::pathsToPublish($provider) as $source => $target) {
                expect(file_exists($source))->toBeTrue();
            }
        }
    }
    $this->installInventorySchema();
    $this->artisan('migrate', ['--database' => 'testing'])->assertSuccessful();
    expect(Schema::hasColumn('inv_cost_layers', 'batch_id'))->toBeTrue()
        ->and(app(AccountingBridge::class))->toBeInstanceOf(NullAccountingBridge::class)
        ->and(app(ApprovalBridge::class))->toBeInstanceOf(NullApprovalBridge::class);

    foreach ($packages as $package) {
        foreach (glob($root . '/packages/' . $package . '/database/migrations/*.php') as $migration) {
            preg_match_all("/Schema::create\\('([^']+)'/", file_get_contents($migration), $matches);
            foreach ($matches[1] as $table) {
                expect(Schema::hasTable($table))->toBeTrue($table);
            }
        }
    }
    $receipt = $this->postReceipt();
    expect($this->postReceipt()->id)->toBe($receipt->id);
    $this->postIssue(2);
    expect(app(InventoryManager::class)->availability(1, 1)->onHandQty)->toBe(8.0);

    // Rollback is tested only on the disposable in-memory database.
    $this->artisan('migrate:rollback', ['--database' => 'testing'])->assertSuccessful();
    expect(Schema::hasTable('inv_items'))->toBeFalse();
    $this->artisan('migrate', ['--database' => 'testing'])->assertSuccessful();
    expect(Schema::hasTable('inv_items'))->toBeTrue();
})->with('ecosystem packages');
