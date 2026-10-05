<?php

use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\Batch;
use ESolution\Inventory\Models\Certificate;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\InventoryFood\DTO\RecipeBatchData;
use ESolution\InventoryFood\DTO\RecipeComponentData;
use ESolution\InventoryFood\Models\RecipeBatch;
use ESolution\InventoryFood\Models\RecipeVersion;
use ESolution\InventoryFood\Services\FoodPreset;
use ESolution\InventoryFood\Services\MadeToOrderTrigger;
use ESolution\InventoryFood\Services\RecipeBatchService;
use ESolution\InventoryFood\Services\RecipeService;

beforeEach(function (): void {
    $this->installInventorySchema();
    foreach ([
        [3, 'FOOD-OUTPUT', 'Prepared Food'],
        [4, 'FOOD-COMPONENT-2', 'Second Ingredient'],
        [5, 'FOOD-HALAL', 'Halal Tracked Food'],
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

function foodReceipt(int $itemId, float $qty, float $cost, string $externalId, ?int $batchId = null): Document
{
    return app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-09-02',
        externalId: $externalId,
        lines: [new LineData($itemId, 1, 1, $qty, unitCost: $cost, batchId: $batchId)],
    ));
}

function publishedFoodRecipe(string $code = 'RECIPE-1', int $outputItemId = 3, int $componentItemId = 1, float $componentQty = 2): RecipeVersion
{
    $recipes = app(RecipeService::class);
    $recipe = $recipes->create($code, $code . ' name', $outputItemId);
    $version = $recipes->createVersion($recipe->id, 1, [
        new RecipeComponentData($componentItemId, 1, $componentQty, 1),
    ]);

    return $recipes->publish($version->id);
}

function foodBatch(string $number, int $versionId, float $qty, string $mode = 'mts', ?int $sourceDocumentId = null, ?int $sourceLineId = null): RecipeBatch
{
    return app(RecipeBatchService::class)->create(new RecipeBatchData(
        batchNo: $number,
        recipeVersionId: $versionId,
        organizationId: 1,
        warehouseId: 1,
        plannedQty: $qty,
        mode: $mode,
        sourceDocumentId: $sourceDocumentId,
        sourceLineId: $sourceLineId,
    ));
}

test('AC9-01 published and used Recipe versions remain immutable and traceable', function (): void {
    $version = publishedFoodRecipe('AC9-01');
    $batch = foodBatch('RB-AC9-01', $version->id, 2);
    $component = $version->components()->firstOrFail();

    expect((int) $batch->recipe_version_id)->toBe($version->id)
        ->and((int) $batch->recipeVersion->version)->toBe(1)
        ->and((int) $batch->recipeVersion->recipe->output_item_id)->toBe(3)
        ->and(function () use ($version): void {
            $version->output_qty = 9;
            $version->save();
        })->toThrow(LogicException::class, 'immutable')
        ->and(function () use ($component): void {
            $component->qty = 9;
            $component->save();
        })->toThrow(LogicException::class, 'immutable');

    $draftV2 = app(RecipeService::class)->createVersion($version->recipe_id, 1, [
        new RecipeComponentData(1, 1, 3),
    ]);
    expect($draftV2->version)->toBe(2)
        ->and((float) $version->refresh()->components()->firstOrFail()->qty)->toBe(2.0);
});

test('AC9-02 Recipe document types use ordinary Core inbound and outbound actions', function (): void {
    foodReceipt(1, 4, 5, 'AC9-02-RAW');
    $consumption = app(InventoryManager::class)->post(new DocumentData(
        type: 'recipe_consumption',
        organizationId: 1,
        trxDate: '2026-09-02',
        externalId: 'AC9-02-CONSUME',
        lines: [new LineData(1, 1, 1, 2)],
    ));
    $receipt = app(InventoryManager::class)->post(new DocumentData(
        type: 'recipe_receipt',
        organizationId: 1,
        trxDate: '2026-09-02',
        externalId: 'AC9-02-RECEIPT',
        lines: [new LineData(3, 1, 1, 1, unitCost: 10)],
    ));
    $registry = app(DocumentTypeRegistry::class);

    expect($registry->get('recipe_consumption')->direction)->toBe('out')
        ->and($registry->get('recipe_receipt')->direction)->toBe('in')
        ->and($consumption->status->value)->toBe('posted')
        ->and($receipt->status->value)->toBe('posted')
        ->and(app(InventoryManager::class)->availability(1, 1)->onHandQty)->toBe(2.0)
        ->and(app(InventoryManager::class)->availability(3, 1)->onHandQty)->toBe(1.0);
});

test('AC9-03 Made-to-Order transition hook creates exactly one RecipeBatch', function (): void {
    $version = publishedFoodRecipe('AC9-03');
    $data = new DocumentData(
        type: 'food_order',
        organizationId: 1,
        trxDate: '2026-09-02',
        externalId: 'FOOD-ORDER-AC9-03',
        sourceType: 'App\\Models\\SalesOrder',
        sourceId: 'SO-AC9-03',
        lines: [new LineData(3, 1, 1, 2, meta: ['recipe_version_id' => $version->id])],
    );
    $source = app(InventoryManager::class)->post($data);
    $retry = app(InventoryManager::class)->post($data);
    app(MadeToOrderTrigger::class)->handle($source, 'posted');
    $batch = RecipeBatch::query()->firstOrFail();

    expect($retry->id)->toBe($source->id)
        ->and(RecipeBatch::query()->count())->toBe(1)
        ->and($batch->mode)->toBe('mto')
        ->and((int) $batch->source_document_id)->toBe($source->id)
        ->and((int) $batch->source_line_id)->toBe($source->lines->firstOrFail()->id)
        ->and($batch->planned_qty)->toBe(2.0);
});

test('AC9-04 MTS RecipeBatch pairs consumption and receipt atomically and idempotently', function (): void {
    foodReceipt(1, 4, 5, 'AC9-04-RAW');
    $version = publishedFoodRecipe('AC9-04');
    $batch = foodBatch('RB-AC9-04', $version->id, 2);
    $completed = app(RecipeBatchService::class)->complete($batch->id, 2);
    $retry = app(RecipeBatchService::class)->complete($batch->id, 2);

    expect($completed->status)->toBe('completed')
        ->and($completed->consumption_document_id)->not->toBeNull()
        ->and($completed->receipt_document_id)->not->toBeNull()
        ->and($completed->consumptionDocument->document_type)->toBe('recipe_consumption')
        ->and($completed->receiptDocument->document_type)->toBe('recipe_receipt')
        ->and($retry->id)->toBe($completed->id)
        ->and(Document::query()->where('source_type', RecipeBatch::class)->count())->toBe(2)
        ->and(app(InventoryManager::class)->availability(1, 1)->onHandQty)->toBe(0.0)
        ->and(app(InventoryManager::class)->availability(3, 1)->onHandQty)->toBe(2.0);
});

test('RecipeBatch rolls back consumption when its paired receipt fails', function (): void {
    foodReceipt(1, 2, 5, 'AC9-04-ROLLBACK-RAW');
    $version = publishedFoodRecipe('AC9-04-ROLLBACK');
    $batch = foodBatch('RB-AC9-04-ROLLBACK', $version->id, 1);
    Item::query()->findOrFail(3)->update(['is_active' => false]);
    $ledgerBefore = StockLedger::query()->count();

    expect(fn() => app(RecipeBatchService::class)->complete($batch->id, 1))
        ->toThrow(DomainException::class, 'inactive');
    expect($batch->refresh()->status)->toBe('planned')
        ->and($batch->consumption_document_id)->toBeNull()
        ->and($batch->receipt_document_id)->toBeNull()
        ->and(Document::query()->where('source_type', RecipeBatch::class)->count())->toBe(0)
        ->and(StockLedger::query()->count())->toBe($ledgerBefore)
        ->and(app(InventoryManager::class)->availability(1, 1)->onHandQty)->toBe(2.0);
});

test('AC9-05 Recipe output cost uses actual consumed component cost', function (): void {
    foodReceipt(1, 10, 7, 'AC9-05-RAW');
    $version = publishedFoodRecipe('AC9-05', componentQty: 2);
    $batch = foodBatch('RB-AC9-05', $version->id, 2);
    $completed = app(RecipeBatchService::class)->complete($batch->id, 2, [1 => 5]);
    $receiptLedger = StockLedger::query()
        ->whereIn('document_line_id', $completed->receiptDocument->lines()->select('id'))
        ->firstOrFail();

    expect($completed->actual_component_cost)->toBe(35.0)
        ->and($completed->output_unit_cost)->toBe(17.5)
        ->and((float) $receiptLedger->amount)->toBe(35.0)
        ->and((float) $receiptLedger->unit_cost)->toBe(17.5);
});

test('AC9-06 Food preset preserves existing policy and enforces a valid Halal certificate', function (): void {
    $item = Item::query()->findOrFail(5);
    $item->tracking = [
        'preserved_setting' => true,
        'required_batch_certificates_on_issue' => ['coa'],
    ];
    $item->save();
    app(FoodPreset::class)->apply($item);
    $item->refresh();
    $batch = Batch::query()->create([
        'item_id' => $item->id,
        'batch_no' => 'FOOD-HALAL-BATCH',
        'status' => 'available',
    ]);
    foodReceipt($item->id, 2, 8, 'AC9-06-RECEIPT', $batch->id);

    expect($item->tracking['preserved_setting'])->toBeTrue()
        ->and($item->tracking['batch_required_on_receipt'])->toBeTrue()
        ->and($item->tracking['required_batch_certificates_on_issue'])->toBe(['coa', 'halal'])
        ->and(fn() => app(InventoryManager::class)->post(new DocumentData(
            type: 'goods_issue',
            organizationId: 1,
            trxDate: '2026-09-02',
            externalId: 'AC9-06-NO-CERT',
            lines: [new LineData($item->id, 1, 1, 1, batchId: $batch->id)],
        )))->toThrow(DomainException::class, 'valid coa');

    foreach (['coa', 'halal'] as $type) {
        Certificate::query()->create([
            'trackable_type' => $batch->getMorphClass(),
            'trackable_id' => $batch->id,
            'type' => $type,
            'number' => strtoupper($type) . '-AC9-06',
            'expires_at' => '2027-12-31',
        ]);
    }
    $issue = app(InventoryManager::class)->post(new DocumentData(
        type: 'goods_issue',
        organizationId: 1,
        trxDate: '2026-09-02',
        externalId: 'AC9-06-VALID',
        lines: [new LineData($item->id, 1, 1, 1, batchId: $batch->id)],
    ));
    expect($issue->status->value)->toBe('posted');
});

test('AC9-07 invalid component Item Types and direct output recursion are rejected', function (): void {
    $recipes = app(RecipeService::class);
    expect(fn() => $recipes->create('BAD-OUTPUT', 'Bad output', 2))
        ->toThrow(DomainException::class, 'Item Type');

    $recipe = $recipes->create('BAD-COMPONENT', 'Bad component', 3);
    expect(fn() => $recipes->createVersion($recipe->id, 1, [new RecipeComponentData(2, 1, 1)]))
        ->toThrow(DomainException::class, 'Item Type')
        ->and(fn() => $recipes->createVersion($recipe->id, 1, [new RecipeComponentData(3, 1, 1)]))
        ->toThrow(DomainException::class, 'own output');
});

test('AC9-08 Food accounting gap fails closed before any stock effect', function (): void {
    foodReceipt(1, 2, 5, 'AC9-08-RAW');
    $version = publishedFoodRecipe('AC9-08');
    $batch = foodBatch('RB-AC9-08', $version->id, 1);
    config()->set('inventory.accounting.enabled', true);
    $ledgerBefore = StockLedger::query()->count();

    expect(fn() => app(RecipeBatchService::class)->complete($batch->id, 1))
        ->toThrow(DomainException::class, 'fail-closed');
    expect($batch->refresh()->status)->toBe('planned')
        ->and(StockLedger::query()->count())->toBe($ledgerBefore)
        ->and(Document::query()->where('source_type', RecipeBatch::class)->count())->toBe(0);
});

test('AC9-09 Food depends only on Core and has no sibling vertical dependency', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        file_get_contents($root . '/packages/food/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $dependencies = array_keys($composer['require']);
    $source = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/packages/food/src'));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source .= file_get_contents($file->getPathname());
        }
    }

    expect($dependencies)->toContain('elgibor-solution/laravel-inventory')
        ->and($source)->not->toContain('InventoryManufacturing\\')
        ->and($source)->not->toContain('InventoryHealthcare\\')
        ->and($source)->not->toContain('InventoryWms\\')
        ->and($source)->not->toContain('StockLedger::create')
        ->and($source)->not->toContain('CostLayer::create');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }
});
