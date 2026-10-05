<?php

use ESolution\Inventory\Bridges\NullAccountingBridge;
use ESolution\Inventory\Bridges\NullApprovalBridge;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\Contracts\MovementPolicyRegistry;
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\DTO\ReservationConsumptionData;
use ESolution\Inventory\Enums\DocumentStatus;
use ESolution\Inventory\Models\DocumentLine;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\PolicyOverride;
use ESolution\Inventory\Models\Reservation;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\Inventory\Tests\Fakes\SpyAccountingBridge;
use ESolution\InventoryRetail\Models\ConsignmentSettlement;
use ESolution\InventoryRetail\Models\ProductFamily;
use ESolution\InventoryRetail\Models\VariantAxis;
use ESolution\InventoryRetail\Models\VariantAxisValue;
use ESolution\InventoryRetail\Services\ConsignmentInventoryService;
use ESolution\InventoryRetail\Services\ConsignmentTermsService;
use ESolution\InventoryRetail\Services\VariantMatrixGenerator;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config()->set('inventory-retail.consignment.enabled', true);
    $this->installInventorySchema();
});

function retailFamily(): ProductFamily
{
    $family = ProductFamily::create([
        'base_sku' => 'TSHIRT',
        'base_name' => 'T-Shirt',
        'item_category_id' => 1,
        'base_uom_id' => 1,
    ]);
    $size = VariantAxis::create(['product_family_id' => $family->id, 'name' => 'Size', 'sort_order' => 1]);
    VariantAxisValue::create(['variant_axis_id' => $size->id, 'code' => 'S', 'value' => 'Small', 'sort_order' => 1]);
    VariantAxisValue::create(['variant_axis_id' => $size->id, 'code' => 'L', 'value' => 'Large', 'sort_order' => 2]);
    $color = VariantAxis::create(['product_family_id' => $family->id, 'name' => 'Color', 'sort_order' => 2]);
    VariantAxisValue::create(['variant_axis_id' => $color->id, 'code' => 'RED', 'value' => 'Red', 'sort_order' => 1]);
    VariantAxisValue::create(['variant_axis_id' => $color->id, 'code' => 'BLUE', 'value' => 'Blue', 'sort_order' => 2]);
    VariantAxisValue::create(['variant_axis_id' => $color->id, 'code' => 'BLACK', 'value' => 'Black', 'sort_order' => 3]);

    return $family;
}

function retailPost(int $itemId, string $type, float $qty, string $externalId, ?int $locationId = null): mixed
{
    return app(InventoryManager::class)->post(new DocumentData(
        type: $type,
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: $externalId,
        lines: [new LineData($itemId, 1, 1, $qty, $locationId, unitCost: $type === 'purchase_receipt' ? 10 : null)],
    ));
}

test('AC5-01 a two by three matrix creates six distinct stock-bearing Core Items', function (): void {
    $family = retailFamily();
    config()->set('inventory-retail.variant_matrix.insert_chunk_size', 2);
    $items = app(VariantMatrixGenerator::class)->generate($family);
    $retry = app(VariantMatrixGenerator::class)->generate($family);

    expect($items)->toHaveCount(6)
        ->and($retry->pluck('id')->all())->toBe($items->pluck('id')->all())
        ->and($items->pluck('sku')->unique())->toHaveCount(6)
        ->and($items->every(fn(Item $item): bool => $item->item_type === 'stock'))->toBeTrue()
        ->and($family->variantLinks()->count())->toBe(6)
        ->and(DB::table('invr_item_variant_link_values')->count())->toBe(12);
});

test('AC5-02 sibling variant availability and Stock Cards remain isolated', function (): void {
    $items = app(VariantMatrixGenerator::class)->generate(retailFamily());
    $redSmall = $items->firstWhere('sku', 'TSHIRT-S-RED');
    $blueSmall = $items->firstWhere('sku', 'TSHIRT-S-BLUE');
    retailPost($redSmall->id, 'purchase_receipt', 2, 'AC5-02-RED-GR');
    retailPost($blueSmall->id, 'purchase_receipt', 3, 'AC5-02-BLUE-GR');
    retailPost($redSmall->id, 'sales_delivery', 2, 'AC5-02-RED-SALE');

    $manager = app(InventoryManager::class);
    expect($manager->availability($redSmall->id, 1)->onHandQty)->toBe(0.0)
        ->and($manager->availability($blueSmall->id, 1)->onHandQty)->toBe(3.0)
        ->and((float) DB::table('inv_stock_cards')->where('item_id', $blueSmall->id)->value('running_qty'))->toBe(3.0);
});

test('AC5-03 and AC5-04 consignment receipt records physical stock without owned valuation', function (): void {
    app(ConsignmentTermsService::class)->configure(1, 'App\\Models\\Supplier', 'SUP-1', referenceUnitCost: 8);
    $accounting = new SpyAccountingBridge();
    app()->instance(AccountingBridge::class, $accounting);

    $document = retailPost(1, 'purchase_receipt', 5, 'AC5-03-CONSIGNMENT-GR');
    $position = app(ConsignmentInventoryService::class)->position(1, 1);

    expect($document->status)->toBe(DocumentStatus::POSTED)
        ->and(StockLedger::query()->where('direction', 'in')->count())->toBe(1)
        ->and((float) DB::table('inv_stock_cards')->where('item_id', 1)->value('running_qty'))->toBe(5.0)
        ->and($position->physicalQty)->toBe(5.0)
        ->and($position->referenceValue)->toBe(40.0)
        ->and($position->ownedValue)->toBe(0.0)
        ->and($accounting->posts)->toBe(0);
});

test('mixed owned and consignment receipt lines fail atomically', function (): void {
    app(ConsignmentTermsService::class)->configure(1, 'App\\Models\\Supplier', 'SUP-1');
    $ownedItem = Item::create([
        'sku' => 'OWNED-STOCK',
        'name' => 'Owned Stock',
        'item_type' => 'stock',
        'item_category_id' => 1,
        'base_uom_id' => 1,
        'is_active' => true,
    ]);

    expect(fn() => app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'AC5-MIXED-OWNERSHIP',
        lines: [
            new LineData(1, 1, 1, 1, unitCost: 10),
            new LineData($ownedItem->id, 1, 1, 1, unitCost: 10),
        ],
    )))->toThrow(DomainException::class, 'cannot mix');

    expect(StockLedger::query()->count())->toBe(0);
});

test('AC5-05 consignment sale creates one traceable settlement and no settlement journal', function (): void {
    app(ConsignmentTermsService::class)->configure(1, 'App\\Models\\Supplier', 'SUP-5');
    retailPost(1, 'purchase_receipt', 5, 'AC5-05-GR');
    $accounting = new SpyAccountingBridge();
    app()->instance(AccountingBridge::class, $accounting);
    app()->forgetInstance(InventoryManager::class);
    app()->forgetInstance(\ESolution\Inventory\Services\PostingEngine::class);

    $sale = retailPost(1, 'sales_delivery', 2, 'AC5-05-SALE');
    $retry = retailPost(1, 'sales_delivery', 2, 'AC5-05-SALE');
    $settlement = ConsignmentSettlement::query()->firstOrFail();

    expect($retry->id)->toBe($sale->id)
        ->and(ConsignmentSettlement::query()->count())->toBe(1)
        ->and((int) $settlement->document_line_id)->toBe((int) $sale->lines->first()->id)
        ->and($settlement->supplier_party_id)->toBe('SUP-5')
        ->and((float) $settlement->qty_sold)->toBe(2.0)
        ->and($settlement->status)->toBe('pending')
        ->and($accounting->posts)->toBe(1);

    $this->artisan('inventory-retail:consignment:settle', ['--through' => '2026-09-01'])
        ->expectsOutput('Marked 1 Consignment obligation(s) as settled. No accounting entry was posted.')
        ->assertSuccessful();
    expect($settlement->refresh()->status)->toBe('settled')
        ->and($accounting->posts)->toBe(1);
});

test('AC5-06 location policy override wins over item policy and terms fall back deterministically', function (): void {
    DB::table('inv_storage_locations')->insert([
        'id' => 10,
        'organization_id' => 1,
        'type' => 'rack',
        'code' => 'R-10',
        'name' => 'Rack 10',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $terms = app(ConsignmentTermsService::class);
    $itemTerm = $terms->configure(1, 'App\\Models\\Supplier', 'ITEM-SUPPLIER');
    $locationTerm = $terms->configure(1, 'App\\Models\\Supplier', 'LOCATION-SUPPLIER', 10);
    PolicyOverride::query()->where('policy_type', 'inventory_model')
        ->where('item_id', 1)
        ->where('location_id', 10)
        ->update(['value' => json_encode(['model' => 'standard'], JSON_THROW_ON_ERROR)]);

    $itemLine = new DocumentLine(['item_id' => 1]);
    $locationLine = new DocumentLine(['item_id' => 1, 'storage_location_id' => 10]);
    $registry = app(MovementPolicyRegistry::class);

    expect($registry->resolvedModel($itemLine))->toBe('consignment')
        ->and($registry->resolvedModel($locationLine))->toBe('standard')
        ->and($terms->resolve(1)->id)->toBe($itemTerm->id)
        ->and($terms->resolve(1, 10)->id)->toBe($locationTerm->id);
});

test('AC5-07 POS sale uses ordinary Core posting without Reservation', function (): void {
    $this->postReceipt(3, externalId: 'AC5-07-POS-GR');
    $sale = app(InventoryManager::class)->post(new DocumentData(
        type: 'sales_delivery',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'AC5-07-POS-SALE',
        partyType: 'App\\Models\\Customer',
        partyId: 'WALK-IN',
        lines: [new LineData(1, 1, 1, 2)],
    ));

    expect($sale->status)->toBe(DocumentStatus::POSTED)
        ->and(Reservation::query()->count())->toBe(0)
        ->and(StockLedger::query()->where('direction', 'out')->count())->toBe(1);
});

test('AC5-08 E-Commerce reuses Core Reservation and atomic fulfillment unchanged', function (): void {
    $this->postReceipt(4, externalId: 'AC5-08-ECOM-GR');
    $manager = app(InventoryManager::class);
    $reservation = $manager->reserve(1, 4, 1, 'App\\Models\\OnlineOrder', 'WEB-100');
    $sale = $manager->post(new DocumentData(
        type: 'sales_delivery',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'AC5-08-ECOM-SHIP',
        sourceType: 'App\\Models\\OnlineOrder',
        sourceId: 'WEB-100',
        lines: [new LineData(1, 1, 1, 4)],
        reservationConsumptions: [
            new ReservationConsumptionData($reservation->id, 1, 4, 'WEB-100-LINE-1'),
        ],
    ));

    expect($sale->status)->toBe(DocumentStatus::POSTED)
        ->and($reservation->refresh()->status)->toBe('consumed')
        ->and($reservation->remaining_qty)->toBe(0.0);
});

test('Retail operates with both optional bridges disabled and has no sibling dependency', function (): void {
    expect(app(AccountingBridge::class))->toBeInstanceOf(NullAccountingBridge::class)
        ->and(app(ApprovalBridge::class))->toBeInstanceOf(NullApprovalBridge::class);

    $composer = json_decode(
        file_get_contents(dirname(__DIR__, 2) . '/packages/retail/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $dependencies = array_keys($composer['require']);
    expect($dependencies)->toContain('elgibor-solution/laravel-inventory');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }
});
