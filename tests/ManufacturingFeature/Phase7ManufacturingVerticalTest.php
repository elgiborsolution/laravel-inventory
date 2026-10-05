<?php

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\InventoryManufacturing\DTO\BomComponentData;
use ESolution\InventoryManufacturing\DTO\ProductionOrderData;
use ESolution\InventoryManufacturing\Models\BomVersion;
use ESolution\InventoryManufacturing\Models\ProductionOrder;
use ESolution\InventoryManufacturing\Models\ProductionVariance;
use ESolution\InventoryManufacturing\Services\BomService;
use ESolution\InventoryManufacturing\Services\ProductionOrderService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->installInventorySchema();
    foreach ([
        [3, 'FINISHED-1', 'Finished Product'],
        [4, 'WIP-1', 'Work in Process'],
        [5, 'COMPONENT-2', 'Second Component'],
    ] as [$id, $sku, $name]) {
        Item::query()->create([
            'id' => $id,
            'sku' => $sku,
            'name' => $name,
            'item_type' => 'stock',
            'item_category_id' => 1,
            'base_uom_id' => 1,
            'is_active' => true,
        ]);
    }
});

function manufacturingReceipt(int $itemId, float $qty, float $cost, string $externalId): Document
{
    return app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-09-02',
        externalId: $externalId,
        lines: [new LineData($itemId, 1, 1, $qty, unitCost: $cost)],
    ));
}

function activatedManufacturingBom(string $code, int $outputItemId, int $componentItemId = 1, float $componentQty = 2): BomVersion
{
    $service = app(BomService::class);
    $bom = $service->create($code, $code . ' BOM', $outputItemId);
    $version = $service->createVersion($bom->id, 1, [
        new BomComponentData($componentItemId, 1, $componentQty, 1),
    ]);

    return $service->activate($version->id);
}

function manufacturingOrder(string $number, int $versionId, float $qty, string $mode = 'mts', ?string $sourceType = null, ?string $sourceId = null, ?int $parentId = null): ProductionOrder
{
    return app(ProductionOrderService::class)->create(new ProductionOrderData(
        orderNo: $number,
        bomVersionId: $versionId,
        organizationId: 1,
        warehouseId: 1,
        plannedQty: $qty,
        sourceMode: $mode,
        sourceType: $sourceType,
        sourceId: $sourceId,
        parentOrderId: $parentId,
    ));
}

test('AC7-01 activated and used BOM versions remain immutable and traceable', function (): void {
    $version = activatedManufacturingBom('AC7-01', 3);
    $order = manufacturingOrder('MO-AC7-01', $version->id, 2);
    $component = $version->components()->firstOrFail();

    expect((int) $order->bom_version_id)->toBe($version->id)
        ->and((int) $order->bomVersion->version)->toBe(1)
        ->and((int) $order->bomVersion->bom->output_item_id)->toBe(3)
        ->and(function () use ($version): void {
            $version->output_qty = 2;
            $version->save();
        })->toThrow(LogicException::class, 'immutable')
        ->and(function () use ($component): void {
            $component->qty = 99;
            $component->save();
        })->toThrow(LogicException::class, 'immutable');

    $draftV2 = app(BomService::class)->createVersion($version->bom_id, 1, [new BomComponentData(1, 1, 3)]);
    expect($draftV2->version)->toBe(2)
        ->and((float) $version->refresh()->components()->firstOrFail()->qty)->toBe(2.0);
});

test('AC7-02 and AC7-03 consumption reduces components and receipt uses actual rolled cost', function (): void {
    manufacturingReceipt(1, 10, 5, 'AC7-02-COMPONENTS');
    $version = activatedManufacturingBom('AC7-02', 3);
    $order = manufacturingOrder('MO-AC7-02', $version->id, 3);

    $completed = app(ProductionOrderService::class)->complete($order->id, 3);
    $retry = app(ProductionOrderService::class)->complete($order->id, 3);
    $consumptionLedger = StockLedger::query()
        ->whereIn('document_line_id', $completed->consumptionDocument->lines()->select('id'))
        ->get();
    $receiptLedger = StockLedger::query()
        ->whereIn('document_line_id', $completed->receiptDocument->lines()->select('id'))
        ->firstOrFail();

    expect((float) $consumptionLedger->sum('qty'))->toBe(6.0)
        ->and((float) $consumptionLedger->sum('amount'))->toBe(30.0)
        ->and((float) $receiptLedger->qty)->toBe(3.0)
        ->and((float) $receiptLedger->unit_cost)->toBe(10.0)
        ->and((float) $receiptLedger->amount)->toBe(30.0)
        ->and($completed->actual_component_cost)->toBe(30.0)
        ->and($completed->output_unit_cost)->toBe(10.0)
        ->and($retry->id)->toBe($completed->id)
        ->and(Document::query()->where('source_type', ProductionOrder::class)->count())->toBe(2)
        ->and(app(InventoryManager::class)->availability(1, 1)->onHandQty)->toBe(4.0)
        ->and(app(InventoryManager::class)->availability(3, 1)->onHandQty)->toBe(3.0);
});

test('AC7-04 consumption and receipt roll back together when output posting fails', function (): void {
    manufacturingReceipt(1, 10, 5, 'AC7-04-COMPONENTS');
    $version = activatedManufacturingBom('AC7-04', 3);
    $order = manufacturingOrder('MO-AC7-04', $version->id, 2);
    Item::query()->findOrFail(3)->update(['is_active' => false]);
    $ledgerBefore = StockLedger::query()->count();

    expect(fn() => app(ProductionOrderService::class)->complete($order->id, 2))
        ->toThrow(DomainException::class, 'inactive');

    expect($order->refresh()->status)->toBe('planned')
        ->and($order->consumption_document_id)->toBeNull()
        ->and($order->receipt_document_id)->toBeNull()
        ->and(Document::query()->where('source_type', ProductionOrder::class)->count())->toBe(0)
        ->and(StockLedger::query()->count())->toBe($ledgerBefore)
        ->and(app(InventoryManager::class)->availability(1, 1)->onHandQty)->toBe(10.0);
});

test('AC7-05 MTS MTO BTO and ATO source references remain explicit and traceable', function (): void {
    $version = activatedManufacturingBom('AC7-05', 3);
    $mts = manufacturingOrder('MO-AC7-05-MTS', $version->id, 1);

    foreach (['mto', 'bto', 'ato'] as $mode) {
        $order = manufacturingOrder(
            'MO-AC7-05-' . strtoupper($mode),
            $version->id,
            1,
            $mode,
            'App\\Models\\SalesOrder',
            'SO-' . strtoupper($mode),
        );
        expect($order->source_mode)->toBe($mode)
            ->and($order->source_type)->toBe('App\\Models\\SalesOrder')
            ->and($order->source_id)->toBe('SO-' . strtoupper($mode));
    }

    expect($mts->source_mode)->toBe('mts')
        ->and($mts->source_type)->toBeNull()
        ->and(fn() => manufacturingOrder('MO-AC7-05-BAD', $version->id, 1, 'mto'))
        ->toThrow(DomainException::class, 'source reference');
});

test('AC7-06 completed WIP output chains into a child Production Order through Core stock', function (): void {
    manufacturingReceipt(1, 4, 5, 'AC7-06-RAW');
    $wipVersion = activatedManufacturingBom('AC7-06-WIP', 4, 1, 2);
    $parent = manufacturingOrder('MO-AC7-06-WIP', $wipVersion->id, 2);
    $parent = app(ProductionOrderService::class)->complete($parent->id, 2);

    $finishedVersion = activatedManufacturingBom('AC7-06-FINISHED', 3, 4, 1);
    $child = manufacturingOrder('MO-AC7-06-FINISHED', $finishedVersion->id, 2, parentId: $parent->id);
    $child = app(ProductionOrderService::class)->complete($child->id, 2);

    expect($parent->status)->toBe('completed')
        ->and($child->status)->toBe('completed')
        ->and((int) $child->parent_order_id)->toBe($parent->id)
        ->and(app(InventoryManager::class)->availability(1, 1)->onHandQty)->toBe(0.0)
        ->and(app(InventoryManager::class)->availability(4, 1)->onHandQty)->toBe(0.0)
        ->and(app(InventoryManager::class)->availability(3, 1)->onHandQty)->toBe(2.0)
        ->and($child->actual_component_cost)->toBe($parent->actual_component_cost);
});

test('AC7-07 scrap and yield variance records are correct and immutable', function (): void {
    manufacturingReceipt(1, 10, 5, 'AC7-07-RAW');
    $version = activatedManufacturingBom('AC7-07', 3, 1, 2);
    $order = manufacturingOrder('MO-AC7-07', $version->id, 2);
    $completed = app(ProductionOrderService::class)->complete($order->id, 1.5, [1 => 5]);
    $scrap = $completed->variances->firstWhere('type', 'scrap');
    $yield = $completed->variances->firstWhere('type', 'yield');

    expect($scrap)->not->toBeNull()
        ->and($scrap->expected_qty)->toBe(4.0)
        ->and($scrap->actual_qty)->toBe(5.0)
        ->and($scrap->difference_qty)->toBe(1.0)
        ->and($scrap->amount)->toBe(5.0)
        ->and($yield)->not->toBeNull()
        ->and($yield->expected_qty)->toBe(2.0)
        ->and($yield->actual_qty)->toBe(1.5)
        ->and($yield->difference_qty)->toBe(-0.5)
        ->and(function () use ($scrap): void {
            $scrap->actual_qty = 99;
            $scrap->save();
        })->toThrow(LogicException::class, 'immutable')
        ->and(fn() => $yield->delete())->toThrow(LogicException::class, 'cannot be deleted');
});

test('Manufacturing accounting remains fail-closed before any stock effect', function (): void {
    manufacturingReceipt(1, 2, 5, 'AC7-ACCOUNTING-RAW');
    $version = activatedManufacturingBom('AC7-ACCOUNTING', 3, 1, 2);
    $order = manufacturingOrder('MO-AC7-ACCOUNTING', $version->id, 1);
    config()->set('inventory.accounting.enabled', true);
    $ledgerBefore = StockLedger::query()->count();

    expect(fn() => app(ProductionOrderService::class)->complete($order->id, 1))
        ->toThrow(DomainException::class, 'fail-closed');
    expect($order->refresh()->status)->toBe('planned')
        ->and(StockLedger::query()->count())->toBe($ledgerBefore)
        ->and(Document::query()->where('source_type', ProductionOrder::class)->count())->toBe(0);
});

test('AC7-08 Manufacturing has no sibling dependency or duplicate Core posting engine', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        file_get_contents($root . '/packages/manufacturing/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $dependencies = array_keys($composer['require']);
    $source = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/packages/manufacturing/src'));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source .= file_get_contents($file->getPathname());
        }
    }

    expect($dependencies)->toContain('elgibor-solution/laravel-inventory')
        ->and($source)->not->toContain('InventoryRetail\\')
        ->and($source)->not->toContain('InventoryWms\\')
        ->and($source)->not->toContain('InventoryHealthcare\\')
        ->and($source)->not->toContain('StockLedger::create')
        ->and($source)->not->toContain('CostLayer::create')
        ->and($source)->not->toContain('implements MovementPolicy');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }
});

test('BOM validation rejects service Items and direct output recursion', function (): void {
    $service = app(BomService::class);
    expect(fn() => $service->create('INVALID-OUTPUT', 'Invalid', 2))
        ->toThrow(DomainException::class, 'Item Type');

    $bom = $service->create('INVALID-COMPONENT', 'Invalid Component', 3);
    expect(fn() => $service->createVersion($bom->id, 1, [new BomComponentData(2, 1, 1)]))
        ->toThrow(DomainException::class, 'Item Type')
        ->and(fn() => $service->createVersion($bom->id, 1, [new BomComponentData(3, 1, 1)]))
        ->toThrow(DomainException::class, 'own output');
});
