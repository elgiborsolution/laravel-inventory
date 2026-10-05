<?php

use ESolution\Inventory\Bridges\NullAccountingBridge;
use ESolution\Inventory\Bridges\NullApprovalBridge;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\Reservation;
use ESolution\Inventory\Models\Serial;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\InventoryAsset\Contracts\OverdueNotifier;
use ESolution\InventoryAsset\DTO\CheckoutData;
use ESolution\InventoryAsset\Models\ActiveAllocation;
use ESolution\InventoryAsset\Models\AssetCheckout;
use ESolution\InventoryAsset\Services\AssetCheckoutService;
use ESolution\InventoryAsset\Services\AssetPreset;
use ESolution\InventoryAsset\Services\OverdueService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->installInventorySchema();
    $item = Item::query()->create([
        'id' => 3,
        'sku' => 'ASSET-1',
        'name' => 'Serialized Asset',
        'item_type' => 'stock',
        'item_category_id' => 1,
        'base_uom_id' => 1,
        'is_active' => true,
        'tracking' => ['preserved_setting' => true],
    ]);
    app(AssetPreset::class)->apply($item);
    $serial = Serial::query()->create([
        'id' => 1,
        'item_id' => $item->id,
        'warehouse_id' => 1,
        'serial_no' => 'ASSET-SERIAL-1',
        'status' => 'in_stock',
    ]);
    app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'ASSET-INITIAL-RECEIPT',
        lines: [new LineData($item->id, 1, 1, 1, unitCost: 100, serialId: $serial->id)],
    ));
});

function assetCheckoutData(string $number = 'CHECKOUT-1', int $serialId = 1, string $borrowerId = 'EMP-1', ?string $dueAt = '2026-09-10 09:00:00'): CheckoutData
{
    return new CheckoutData(
        checkoutNo: $number,
        serialId: $serialId,
        warehouseId: 1,
        borrowerType: 'employee',
        borrowerId: $borrowerId,
        checkedOutAt: '2026-09-02 09:00:00',
        dueAt: $dueAt,
    );
}

test('AC10-01 Asset preset merges and enables required serial tracking', function (): void {
    $item = Item::query()->findOrFail(3);

    expect($item->tracking['preserved_setting'])->toBeTrue()
        ->and($item->tracking['asset_checkout_enabled'])->toBeTrue()
        ->and($item->tracking['serial_required_on_receipt'])->toBeTrue()
        ->and($item->tracking['serial_required_on_issue'])->toBeTrue();

    $second = Item::query()->create([
        'id' => 4,
        'sku' => 'ASSET-2',
        'name' => 'Asset without receipt serial',
        'item_type' => 'stock',
        'item_category_id' => 1,
        'base_uom_id' => 1,
        'is_active' => true,
    ]);
    app(AssetPreset::class)->apply($second);
    expect(fn() => app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-09-02',
        externalId: 'ASSET-MISSING-SERIAL',
        lines: [new LineData($second->id, 1, 1, 1, unitCost: 50)],
    )))->toThrow(DomainException::class, 'requires a serial');
});

test('AC10-02 checkout reserves exactly one valid serialized Asset and is idempotent', function (): void {
    $service = app(AssetCheckoutService::class);
    $checkout = $service->checkout(assetCheckoutData());
    $retry = $service->checkout(assetCheckoutData());

    expect($retry->id)->toBe($checkout->id)
        ->and($checkout->item_id)->toBe(3)
        ->and($checkout->serial_id)->toBe(1)
        ->and($checkout->status)->toBe('active')
        ->and($checkout->reservation->reserved_qty)->toEqual(1)
        ->and($checkout->reservation->status)->toBe('active')
        ->and(Reservation::query()->count())->toBe(1)
        ->and(ActiveAllocation::query()->where('serial_id', 1)->count())->toBe(1);
});

test('AC10-03 check-in releases Reservation without a stock movement', function (): void {
    $service = app(AssetCheckoutService::class);
    $checkout = $service->checkout(assetCheckoutData());
    $ledgerBefore = StockLedger::query()->count();
    $checkedIn = $service->checkin($checkout->id, '2026-09-03 09:00:00');
    $retry = $service->checkin($checkout->id, '2026-09-03 09:00:00');

    expect($checkedIn->status)->toBe('checked_in')
        ->and($checkedIn->checked_in_at?->toDateTimeString())->toBe('2026-09-03 09:00:00')
        ->and($checkedIn->reservation->status)->toBe('released')
        ->and((float) $checkedIn->reservation->released_qty)->toBe(1.0)
        ->and(ActiveAllocation::query()->where('serial_id', 1)->doesntExist())->toBeTrue()
        ->and(StockLedger::query()->count())->toBe($ledgerBefore)
        ->and($retry->id)->toBe($checkedIn->id);
});

test('AC10-04 on-hand stays unchanged throughout the Asset loan', function (): void {
    $inventory = app(InventoryManager::class);
    $service = app(AssetCheckoutService::class);
    $before = $inventory->availability(3, 1);
    $checkout = $service->checkout(assetCheckoutData());
    $during = $inventory->availability(3, 1);
    $service->checkin($checkout->id);
    $after = $inventory->availability(3, 1);

    expect($before->onHandQty)->toBe(1.0)
        ->and($before->availableQty)->toBe(1.0)
        ->and($during->onHandQty)->toBe(1.0)
        ->and($during->reservedQty)->toBe(1.0)
        ->and($during->availableQty)->toBe(0.0)
        ->and($after->onHandQty)->toBe(1.0)
        ->and($after->reservedQty)->toBe(0.0)
        ->and($after->availableQty)->toBe(1.0)
        ->and(Serial::query()->findOrFail(1)->status)->toBe('in_stock');
});

test('AC10-05 invalid Item Type preset and invalid serial are rejected', function (): void {
    $serviceItem = Item::query()->findOrFail(2);
    expect(fn() => app(AssetPreset::class)->apply($serviceItem))
        ->toThrow(DomainException::class, 'Item Type');

    $invalidSerial = Serial::query()->create([
        'item_id' => 3,
        'warehouse_id' => 1,
        'serial_no' => 'ASSET-SERIAL-INVALID',
        'status' => 'issued',
    ]);
    expect(fn() => app(AssetCheckoutService::class)->checkout(
        assetCheckoutData('CHECKOUT-INVALID', $invalidSerial->id),
    ))->toThrow(DomainException::class, 'not valid');
});

test('AC10-06 portable unique allocation prevents concurrent double checkout', function (): void {
    $service = app(AssetCheckoutService::class);
    $first = $service->checkout(assetCheckoutData('CHECKOUT-FIRST'));

    expect(fn() => $service->checkout(assetCheckoutData('CHECKOUT-SECOND', borrowerId: 'EMP-2')))
        ->toThrow(DomainException::class, 'active allocation');

    $shadowReservation = Reservation::query()->create([
        'item_id' => 3,
        'warehouse_id' => 1,
        'source_type' => AssetCheckout::class,
        'source_id' => 'CONCURRENT-SHADOW',
        'reserved_qty' => 1,
        'status' => 'active',
    ]);
    $shadow = AssetCheckout::query()->create([
        'checkout_no' => 'CONCURRENT-SHADOW',
        'item_id' => 3,
        'serial_id' => 1,
        'warehouse_id' => 1,
        'reservation_id' => $shadowReservation->id,
        'borrower_type' => 'employee',
        'borrower_id' => 'EMP-SHADOW',
        'status' => 'active',
        'checked_out_at' => now(),
    ]);

    expect(fn() => ActiveAllocation::query()->create([
        'serial_id' => 1,
        'checkout_id' => $shadow->id,
    ]))->toThrow(QueryException::class)
        ->and(ActiveAllocation::query()->where('serial_id', 1)->value('checkout_id'))->toBe($first->id);
});

test('AC10-07 overdue is derived and detection is notification-only', function (): void {
    $checkout = app(AssetCheckoutService::class)->checkout(assetCheckoutData(
        'CHECKOUT-OVERDUE',
        dueAt: '2026-09-03 09:00:00',
    ));
    $ledgerBefore = StockLedger::query()->count();
    $reservationBefore = $checkout->reservation->toArray();
    $notifier = new class implements OverdueNotifier {
        /** @var list<int> */
        public array $checkoutIds = [];

        public function notify(AssetCheckout $checkout): void
        {
            $this->checkoutIds[] = (int) $checkout->getKey();
        }
    };
    app()->instance(OverdueNotifier::class, $notifier);
    app()->forgetInstance(OverdueService::class);
    $detected = app(OverdueService::class)->detect(Carbon::parse('2026-09-04 09:00:00'));

    expect($checkout->isOverdueAt(Carbon::parse('2026-09-04 09:00:00')))->toBeTrue()
        ->and($detected->pluck('id')->all())->toBe([$checkout->id])
        ->and($notifier->checkoutIds)->toBe([$checkout->id])
        ->and($checkout->refresh()->status)->toBe('active')
        ->and($checkout->reservation->refresh()->toArray())->toBe($reservationBefore)
        ->and(StockLedger::query()->count())->toBe($ledgerBefore);
});

test('AC10-08 Asset adds no Document Type MovementPolicy or CostingDriver', function (): void {
    $types = array_keys(app(DocumentTypeRegistry::class)->all());
    $root = dirname(__DIR__, 2) . '/packages/asset/src';
    $source = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source .= file_get_contents($file->getPathname());
        }
    }

    expect($types)->not->toContain('asset_checkout')
        ->and($types)->not->toContain('asset_checkin')
        ->and($source)->not->toContain('DocumentTypeDefinition')
        ->and($source)->not->toContain('implements MovementPolicy')
        ->and($source)->not->toContain('implements CostingDriver');
});

test('AC10-09 Asset has no sibling dependency and works with bridges disabled', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        file_get_contents($root . '/packages/asset/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $dependencies = array_keys($composer['require']);
    $source = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/packages/asset/src'));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source .= file_get_contents($file->getPathname());
        }
    }
    $checkout = app(AssetCheckoutService::class)->checkout(assetCheckoutData('CHECKOUT-NO-BRIDGES'));

    expect(app(AccountingBridge::class))->toBeInstanceOf(NullAccountingBridge::class)
        ->and(app(ApprovalBridge::class))->toBeInstanceOf(NullApprovalBridge::class)
        ->and($checkout->status)->toBe('active')
        ->and($dependencies)->toContain('elgibor-solution/laravel-inventory')
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
