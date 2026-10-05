<?php

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Events\DocumentPosted;
use ESolution\Inventory\Models\Batch;
use ESolution\Inventory\Models\PolicyOverride;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\InventoryWms\DTO\PickingRequest;
use ESolution\InventoryWms\DTO\PutAwayRequest;
use ESolution\InventoryWms\Models\CrossDockRoute;
use ESolution\InventoryWms\Models\LocationProfile;
use ESolution\InventoryWms\Models\PutAwayRule;
use ESolution\InventoryWms\Models\ReplenishmentRule;
use ESolution\InventoryWms\Models\Task;
use ESolution\InventoryWms\Services\LpnService;
use ESolution\InventoryWms\Services\PickingManager;
use ESolution\InventoryWms\Services\PutAwayManager;
use ESolution\InventoryWms\Services\ReplenishmentScheduler;
use ESolution\InventoryWms\Services\TaskOrchestrator;
use ESolution\InventoryWms\Services\WaveService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->installInventorySchema();
    foreach ([
        [10, 'RECEIVING', 'Receiving Dock'],
        [11, 'BIN-A', 'Bin A'],
        [12, 'BIN-B', 'Bin B'],
        [13, 'BIN-DED', 'Dedicated Bin'],
        [14, 'STAGE', 'Cross Dock Stage'],
    ] as [$id, $code, $name]) {
        DB::table('inv_storage_locations')->insert([
            'id' => $id,
            'organization_id' => 1,
            'type' => 'bin',
            'code' => $code,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    LocationProfile::create([
        'storage_location_id' => 10,
        'travel_sequence' => 0,
        'put_away_enabled' => false,
        'picking_enabled' => false,
    ]);
    LocationProfile::create(['storage_location_id' => 11, 'travel_sequence' => 10, 'capacity_qty' => 100]);
    LocationProfile::create(['storage_location_id' => 12, 'travel_sequence' => 20, 'capacity_qty' => 100]);
    LocationProfile::create([
        'storage_location_id' => 13,
        'travel_sequence' => 30,
        'capacity_qty' => 100,
        'dedicated_item_id' => 1,
    ]);
    LocationProfile::create([
        'storage_location_id' => 14,
        'travel_sequence' => 40,
        'put_away_enabled' => false,
        'picking_enabled' => false,
    ]);
});

function wmsReceipt(float $qty, string $externalId, int $locationId, ?int $batchId = null, string $date = '2026-09-01'): mixed
{
    return app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: $date,
        externalId: $externalId,
        lines: [new LineData(1, 1, 1, $qty, $locationId, unitCost: 10, batchId: $batchId)],
    ));
}

test('AC6-01 all put-away strategies return valid deterministic locations', function (): void {
    PutAwayRule::create([
        'warehouse_id' => 1,
        'item_id' => 1,
        'strategy' => 'fixed',
        'fixed_location_id' => 12,
        'priority' => 1,
    ]);
    $manager = app(PutAwayManager::class);
    $request = new PutAwayRequest(1, 1, 5, 10, deterministicKey: 'AC6-01');

    expect($manager->suggest($request, 'fixed')->id)->toBe(12)
        ->and($manager->suggest($request, 'dynamic')->id)->toBe(11)
        ->and($manager->suggest($request, 'dedicated')->id)->toBe(13)
        ->and($manager->suggest($request, 'nearest')->id)->toBe(11)
        ->and($manager->suggest($request, 'empty_bin')->id)->toBe(11)
        ->and($manager->suggest($request, 'random')->id)->toBe($manager->suggest($request, 'random')->id);
});

test('AC6-02 FIFO and FEFO picking produce deterministic complete suggestions', function (): void {
    $fifoBatch = Batch::create(['item_id' => 1, 'batch_no' => 'FIFO', 'expires_at' => '2027-12-31']);
    $fefoBatch = Batch::create(['item_id' => 1, 'batch_no' => 'FEFO', 'expires_at' => '2026-12-31']);
    wmsReceipt(4, 'AC6-02-OLD', 11, $fifoBatch->id, '2026-08-01');
    wmsReceipt(4, 'AC6-02-NEW', 12, $fefoBatch->id, '2026-09-01');

    $manager = app(PickingManager::class);
    $fifo = $manager->suggest(new PickingRequest(1, 1, 5), 'fifo');
    $fefo = $manager->suggest(new PickingRequest(1, 1, 5), 'fefo');

    expect($fifo)->toHaveCount(2)
        ->and($fifo[0]->locationId)->toBe(11)
        ->and($fifo[0]->qty)->toBe(4.0)
        ->and($fefo)->toHaveCount(2)
        ->and($fefo[0]->locationId)->toBe(12)
        ->and(array_sum(array_map(fn($suggestion): float => $suggestion->qty, $fefo)))->toBe(5.0)
        ->and($manager->suggest(new PickingRequest(1, 1, 5), 'fefo'))->toEqual($fefo);
});

test('AC6-03 document hooks and wave creation are retry safe', function (): void {
    $receipt = wmsReceipt(2, 'AC6-03-GR', 10);
    expect(Task::query()->where('type', 'put_away')->count())->toBe(1);

    app(TaskOrchestrator::class)->handle(new DocumentPosted($receipt));
    app(TaskOrchestrator::class)->handle(new DocumentPosted($receipt));
    expect(Task::query()->where('type', 'put_away')->count())->toBe(1);

    $task = Task::query()->where('type', 'put_away')->firstOrFail();
    $task->update(['type' => 'pick']);
    $first = app(WaveService::class)->create('WAVE-AC6-03', 1, [$task->id]);
    $retry = app(WaveService::class)->create('WAVE-AC6-03', 1, [$task->id]);
    expect($first->id)->toBe($retry->id)
        ->and(DB::table('invw_wave_tasks')->count())->toBe(1);
});

test('AC6-04 LPN contents and warehouse location stay consistent', function (): void {
    $service = app(LpnService::class);
    $lpn = $service->create('LPN-AC6-04', 1, 11);
    $service->add($lpn->id, 1, 8);
    $service->add($lpn->id, 1, 2);
    $service->remove($lpn->id, 1, 3);
    $service->relocate($lpn->id, 12);

    expect((float) $lpn->contents()->firstOrFail()->qty)->toBe(7.0)
        ->and((int) $lpn->refresh()->storage_location_id)->toBe(12)
        ->and(fn() => $service->remove($lpn->id, 1, 8))->toThrow(DomainException::class)
        ->and((float) $lpn->contents()->firstOrFail()->qty)->toBe(7.0);
});

test('AC6-05 replenishment creates only idempotent internal work', function (): void {
    wmsReceipt(10, 'AC6-05-SOURCE', 11);
    ReplenishmentRule::create([
        'item_id' => 1,
        'warehouse_id' => 1,
        'source_location_id' => 11,
        'pick_location_id' => 12,
        'minimum_qty' => 3,
        'target_qty' => 6,
    ]);
    $ledgerCount = StockLedger::query()->count();

    $first = app(ReplenishmentScheduler::class)->schedule(1);
    $retry = app(ReplenishmentScheduler::class)->schedule(1);

    expect($first)->toHaveCount(1)
        ->and($retry)->toHaveCount(1)
        ->and($first[0]->id)->toBe($retry[0]->id)
        ->and($first[0]->type)->toBe('replenishment')
        ->and((float) $first[0]->qty)->toBe(6.0)
        ->and(StockLedger::query()->count())->toBe($ledgerCount);
});

test('AC6-06 cross docking changes task routing without changing Core cost or ledger', function (): void {
    CrossDockRoute::create([
        'item_id' => 1,
        'warehouse_id' => 1,
        'staging_location_id' => 14,
        'priority' => 1,
    ]);
    PolicyOverride::create([
        'policy_type' => 'inventory_model',
        'item_id' => 1,
        'value' => ['model' => 'cross_dock'],
    ]);

    $document = wmsReceipt(3, 'AC6-06-XDOCK', 10);
    $task = Task::query()->where('document_id', $document->id)->firstOrFail();
    $ledger = StockLedger::query()->whereIn('document_line_id', $document->lines->pluck('id'))->firstOrFail();

    expect($task->type)->toBe('cross_dock')
        ->and((int) $task->from_location_id)->toBe(10)
        ->and((int) $task->to_location_id)->toBe(14)
        ->and((float) $ledger->qty)->toBe(3.0)
        ->and((float) $ledger->unit_cost)->toBe(10.0)
        ->and((float) $ledger->amount)->toBe(30.0)
        ->and(StockLedger::query()->count())->toBe(1);
});

test('AC6-07 WMS depends only on Core and contains no sibling vertical references', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(file_get_contents($root . '/packages/wms/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $dependencies = array_keys($composer['require']);
    $source = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/packages/wms/src'));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source .= file_get_contents($file->getPathname());
        }
    }

    expect($dependencies)->toContain('elgibor-solution/laravel-inventory');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }
    expect($source)->not->toContain('InventoryRetail\\')
        ->and($source)->not->toContain('InventoryManufacturing\\')
        ->and($source)->not->toContain('InventoryHealthcare\\');
});
