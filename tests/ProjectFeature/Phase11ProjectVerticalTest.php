<?php

use ESolution\Inventory\Bridges\NullAccountingBridge;
use ESolution\Inventory\Bridges\NullApprovalBridge;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\Organization;
use ESolution\Inventory\Models\Reservation;
use ESolution\Inventory\Models\ReservationConsumption;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\InventoryProject\DTO\AllocationData;
use ESolution\InventoryProject\Models\ProjectAllocation;
use ESolution\InventoryProject\Models\ProjectReallocation;
use ESolution\InventoryProject\Services\ProjectAllocationReport;
use ESolution\InventoryProject\Services\ProjectAllocationService;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->installInventorySchema();
    Organization::query()->create([
        'id' => 3,
        'type' => 'branch',
        'code' => 'PROJECT-SITE-1',
        'name' => 'Project Site One',
        'is_active' => true,
    ]);
    Organization::query()->create([
        'id' => 4,
        'parent_id' => 3,
        'type' => 'warehouse',
        'code' => 'PROJECT-WH-1',
        'name' => 'Project Warehouse One',
        'is_active' => true,
    ]);
    Organization::query()->create([
        'id' => 5,
        'type' => 'branch',
        'code' => 'PROJECT-SITE-2',
        'name' => 'Project Site Two',
        'is_active' => true,
    ]);
    Organization::query()->create([
        'id' => 6,
        'parent_id' => 5,
        'type' => 'warehouse',
        'code' => 'PROJECT-WH-2',
        'name' => 'Project Warehouse Two',
        'is_active' => true,
    ]);
    projectReceipt(4, 20, 'PROJECT-WH-1-RECEIPT');
    projectReceipt(6, 3, 'PROJECT-WH-2-RECEIPT');
});

function projectReceipt(int $warehouseId, float $qty, string $externalId): Document
{
    return app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: $warehouseId === 4 ? 3 : 5,
        trxDate: '2026-09-07',
        externalId: $externalId,
        lines: [new LineData(1, 1, $warehouseId, $qty, unitCost: 10)],
    ));
}

function projectAllocationData(
    string $number,
    float $qty,
    int $siteId = 3,
    int $warehouseId = 4,
    string $projectId = 'PROJECT-1',
): AllocationData {
    return new AllocationData(
        allocationNo: $number,
        projectType: 'App\\Models\\Project',
        projectId: $projectId,
        siteId: $siteId,
        warehouseId: $warehouseId,
        itemId: 1,
        qty: $qty,
    );
}

test('AC11-01 allocation creates one matching Core Reservation', function (): void {
    $allocation = app(ProjectAllocationService::class)->allocate(projectAllocationData('ALLOC-AC11-01', 5));
    $retry = app(ProjectAllocationService::class)->allocate(projectAllocationData('ALLOC-AC11-01', 5));

    expect($retry->id)->toBe($allocation->id)
        ->and($allocation->project_type)->toBe('App\\Models\\Project')
        ->and($allocation->project_id)->toBe('PROJECT-1')
        ->and($allocation->reservation->item_id)->toBe(1)
        ->and($allocation->reservation->warehouse_id)->toBe(4)
        ->and($allocation->reservation->source_type)->toBe(ProjectAllocation::class)
        ->and($allocation->reservation->source_id)->toBe('ALLOC-AC11-01')
        ->and((float) $allocation->reservation->reserved_qty)->toBe(5.0)
        ->and(Reservation::query()->count())->toBe(1);
});

test('AC11-02 Site uses the Core organization hierarchy without a new level or table', function (): void {
    $allocation = app(ProjectAllocationService::class)->allocate(projectAllocationData('ALLOC-AC11-02', 2));

    expect($allocation->site->getTable())->toBe('inv_organizations')
        ->and($allocation->warehouse->getTable())->toBe('inv_organizations')
        ->and($allocation->site->type)->toBe('branch')
        ->and($allocation->warehouse->type)->toBe('warehouse')
        ->and((int) $allocation->warehouse->parent_id)->toBe($allocation->site->id)
        ->and(Schema::hasTable('invp_sites'))->toBeFalse();

    expect(fn() => app(ProjectAllocationService::class)->allocate(
        projectAllocationData('ALLOC-WRONG-HIERARCHY', 1, siteId: 5, warehouseId: 4),
    ))->toThrow(DomainException::class, 'descendant');
});

test('AC11-03 replenishment creates a separate allocation and Reservation', function (): void {
    $service = app(ProjectAllocationService::class);
    $source = $service->allocate(projectAllocationData('ALLOC-AC11-03', 5));
    $replenishment = $service->replenish($source->id, 'ALLOC-AC11-03-R1', 3);

    expect($replenishment->id)->not->toBe($source->id)
        ->and($replenishment->reservation_id)->not->toBe($source->reservation_id)
        ->and($replenishment->kind)->toBe('replenishment')
        ->and((int) $replenishment->source_allocation_id)->toBe($source->id)
        ->and((float) $source->reservation->refresh()->reserved_qty)->toBe(5.0)
        ->and((float) $source->reservation->released_qty)->toBe(0.0)
        ->and(ProjectAllocation::query()->count())->toBe(2)
        ->and(Reservation::query()->count())->toBe(2);
});

test('AC11-04 reallocation is explicit atomic and balance-safe', function (): void {
    $service = app(ProjectAllocationService::class);
    $source = $service->allocate(projectAllocationData('ALLOC-AC11-04-SOURCE', 4));

    expect(fn() => $service->reallocate(
        'REALLOC-AC11-04-FAIL',
        $source->id,
        'ALLOC-AC11-04-DEST-FAIL',
        5,
        6,
        4,
    ))->toThrow(DomainException::class, 'Insufficient available stock');
    expect((float) $source->reservation->refresh()->released_qty)->toBe(0.0)
        ->and($source->refresh()->status)->toBe('active')
        ->and(ProjectAllocation::query()->count())->toBe(1)
        ->and(ProjectReallocation::query()->count())->toBe(0);

    $reallocation = $service->reallocate(
        'REALLOC-AC11-04',
        $source->id,
        'ALLOC-AC11-04-DEST',
        5,
        6,
        3,
    );
    $retry = $service->reallocate('REALLOC-AC11-04', $source->id, 'ALLOC-AC11-04-DEST', 5, 6, 3);
    $destination = $reallocation->destinationAllocation;

    expect($retry->id)->toBe($reallocation->id)
        ->and((int) $reallocation->source_allocation_id)->toBe($source->id)
        ->and((int) $reallocation->destination_allocation_id)->toBe($destination->id)
        ->and($destination->kind)->toBe('reallocation')
        ->and((float) $source->reservation->refresh()->released_qty)->toBe(3.0)
        ->and($source->reservation->remaining_qty)->toBe(1.0)
        ->and((float) $destination->reservation->reserved_qty)->toBe(3.0)
        ->and($destination->reservation->remaining_qty)->toBe(3.0);
});

test('AC11-05 partial draws consume the exact remaining Reservation through linked Goods Issues', function (): void {
    $service = app(ProjectAllocationService::class);
    $allocation = $service->allocate(projectAllocationData('ALLOC-AC11-05', 6));
    $first = $service->draw($allocation->id, 2, 'DRAW-AC11-05-1', '2026-09-07');
    $retry = $service->draw($allocation->id, 2, 'DRAW-AC11-05-1', '2026-09-07');
    $service->draw($allocation->id, 3, 'DRAW-AC11-05-2', '2026-09-07');

    expect($retry->id)->toBe($first->id)
        ->and((float) $allocation->reservation->refresh()->consumed_qty)->toBe(5.0)
        ->and($allocation->reservation->remaining_qty)->toBe(1.0)
        ->and($allocation->refresh()->status)->toBe('active')
        ->and(ReservationConsumption::query()->where('reservation_id', $allocation->reservation_id)->pluck('qty')->map(fn($qty): float => (float) $qty)->all())->toBe([2.0, 3.0]);

    $service->draw($allocation->id, 1, 'DRAW-AC11-05-3', '2026-09-07');
    expect($allocation->reservation->refresh()->status)->toBe('consumed')
        ->and($allocation->refresh()->status)->toBe('consumed')
        ->and((float) ReservationConsumption::query()->where('reservation_id', $allocation->reservation_id)->sum('qty'))->toBe(6.0)
        ->and(app(InventoryManager::class)->availability(1, 4)->onHandQty)->toBe(14.0);
});

test('AC11-06 reporting totals allocation consumption release and remaining from exact records', function (): void {
    $service = app(ProjectAllocationService::class);
    $initial = $service->allocate(projectAllocationData('ALLOC-AC11-06', 5));
    $replenishment = $service->replenish($initial->id, 'ALLOC-AC11-06-R1', 3);
    $service->draw($initial->id, 2, 'DRAW-AC11-06', '2026-09-07');
    $service->reallocate('REALLOC-AC11-06', $replenishment->id, 'ALLOC-AC11-06-DEST', 5, 6, 1);

    $all = app(ProjectAllocationReport::class)->totals('App\\Models\\Project', 'PROJECT-1');
    $siteOne = app(ProjectAllocationReport::class)->totals('App\\Models\\Project', 'PROJECT-1', 3, 1);

    expect($all->allocatedQty)->toBe(9.0)
        ->and($all->consumedQty)->toBe(2.0)
        ->and($all->releasedQty)->toBe(1.0)
        ->and($all->remainingQty)->toBe(6.0)
        ->and($all->allocationCount)->toBe(3)
        ->and($siteOne->allocatedQty)->toBe(8.0)
        ->and($siteOne->consumedQty)->toBe(2.0)
        ->and($siteOne->releasedQty)->toBe(1.0)
        ->and($siteOne->remainingQty)->toBe(5.0)
        ->and($siteOne->allocationCount)->toBe(2);
});

test('allocation locking and availability checks prevent oversubscription', function (): void {
    $service = app(ProjectAllocationService::class);
    $first = $service->allocate(projectAllocationData('ALLOC-CONCURRENT-1', 15));
    $retry = $service->allocate(projectAllocationData('ALLOC-CONCURRENT-1', 15));

    expect($retry->id)->toBe($first->id)
        ->and(fn() => $service->allocate(projectAllocationData('ALLOC-CONCURRENT-2', 6)))
        ->toThrow(DomainException::class, 'Insufficient available stock')
        ->and(ProjectAllocation::query()->count())->toBe(1)
        ->and(Reservation::query()->count())->toBe(1)
        ->and(app(InventoryManager::class)->availability(1, 4)->availableQty)->toBe(5.0);
});

test('AC11-07 Project introduces no stock movement costing or document behavior', function (): void {
    $root = dirname(__DIR__, 2) . '/packages/project/src';
    $source = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source .= file_get_contents($file->getPathname());
        }
    }
    $types = array_keys(app(DocumentTypeRegistry::class)->all());

    expect($types)->not->toContain('project_allocation')
        ->and($types)->not->toContain('project_draw')
        ->and($source)->not->toContain('DocumentTypeDefinition')
        ->and($source)->not->toContain('implements MovementPolicy')
        ->and($source)->not->toContain('implements CostingDriver')
        ->and($source)->not->toContain('StockLedger::create');
});

test('AC11-08 Project publishes no sector preset', function (): void {
    $root = dirname(__DIR__, 2) . '/packages/project';
    $provider = file_get_contents($root . '/src/ProjectServiceProvider.php');

    expect(is_dir($root . '/config'))->toBeFalse()
        ->and($provider)->not->toContain('mergeConfigFrom')
        ->and($provider)->not->toContain('inventory-project-config')
        ->and(class_exists('ESolution\\InventoryProject\\Services\\ProjectPreset'))->toBeFalse();
});

test('AC11-09 Project has no sibling dependency and works with bridges disabled', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        file_get_contents($root . '/packages/project/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $dependencies = array_keys($composer['require']);
    $source = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/packages/project/src'));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source .= file_get_contents($file->getPathname());
        }
    }
    $allocation = app(ProjectAllocationService::class)->allocate(projectAllocationData('ALLOC-NO-BRIDGES', 1));

    expect(app(AccountingBridge::class))->toBeInstanceOf(NullAccountingBridge::class)
        ->and(app(ApprovalBridge::class))->toBeInstanceOf(NullApprovalBridge::class)
        ->and($allocation->status)->toBe('active')
        ->and($dependencies)->toContain('elgibor-solution/laravel-inventory')
        ->and($source)->not->toContain('InventoryAsset\\')
        ->and($source)->not->toContain('InventoryFood\\')
        ->and($source)->not->toContain('InventoryHealthcare\\')
        ->and($source)->not->toContain('InventoryManufacturing\\')
        ->and($source)->not->toContain('InventoryWms\\');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }
});
