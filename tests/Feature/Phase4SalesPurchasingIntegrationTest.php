<?php

use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\DTO\ReservationConsumptionData;
use ESolution\Inventory\Enums\DocumentStatus;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\Reservation;
use ESolution\Inventory\Models\ReservationConsumption;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\Inventory\Tests\Fakes\SpyAccountingBridge;
use ESolution\Inventory\Tests\Fakes\SpyApprovalBridge;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->installInventorySchema();
});

function phase4IssueData(
    string $externalId,
    string $sourceId,
    float $quantity,
    array $consumptions = [],
): DocumentData {
    return new DocumentData(
        type: 'sales_delivery',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: $externalId,
        sourceType: 'App\\Models\\SalesOrder',
        sourceId: $sourceId,
        partyType: 'App\\Models\\Customer',
        partyId: 'CUSTOMER-1',
        lines: [new LineData(1, 1, 1, $quantity)],
        reservationConsumptions: $consumptions,
    );
}

test('AC4-01 sales confirmation reserves arbitrary source and only decreases availability', function (): void {
    $this->postReceipt(10, externalId: 'AC4-01-GR');
    $manager = app(InventoryManager::class);
    $beforeLedger = StockLedger::query()->count();

    $reservation = $manager->reserve(1, 4, 1, 'App\\Models\\CustomSalesOrder', 'SO-AC4-01');
    $availability = $manager->availability(1, 1);

    expect($reservation->status)->toBe('active')
        ->and($availability->onHandQty)->toBe(10.0)
        ->and($availability->reservedQty)->toBe(4.0)
        ->and($availability->availableQty())->toBe(6.0)
        ->and(StockLedger::query()->count())->toBe($beforeLedger);
});

test('AC4-02 fulfillment posts issue and consumes the exact reservation atomically', function (): void {
    $this->postReceipt(10, externalId: 'AC4-02-GR');
    $manager = app(InventoryManager::class);
    $reservation = $manager->reserve(1, 4, 1, 'App\\Models\\SalesOrder', 'SO-AC4-02');

    $document = $manager->post(phase4IssueData('AC4-02-GI', 'SO-AC4-02', 4, [
        new ReservationConsumptionData($reservation->getKey(), 1, 4, 'SHIPMENT-AC4-02'),
    ]));
    $consumption = ReservationConsumption::query()->firstOrFail();

    expect($document->status)->toBe(DocumentStatus::POSTED)
        ->and($reservation->refresh()->status)->toBe('consumed')
        ->and((float) $reservation->consumed_qty)->toBe(4.0)
        ->and((int) $consumption->document_line_id)->toBe((int) $document->lines->first()->getKey())
        ->and((float) $manager->availability(1, 1)->availableQty())->toBe(6.0);
});

test('fulfillment retry returns the same document without duplicate consumption', function (): void {
    $this->postReceipt(5, externalId: 'AC4-RETRY-GR');
    $manager = app(InventoryManager::class);
    $reservation = $manager->reserve(1, 2, 1, 'App\\Models\\SalesOrder', 'SO-AC4-RETRY');
    $data = phase4IssueData('AC4-RETRY-GI', 'SO-AC4-RETRY', 2, [
        new ReservationConsumptionData($reservation->getKey(), 1, 2, 'SHIPMENT-RETRY'),
    ]);

    $first = $manager->post($data);
    $retry = $manager->post($data);

    expect($retry->getKey())->toBe($first->getKey())
        ->and(ReservationConsumption::query()->count())->toBe(1)
        ->and((float) $reservation->refresh()->consumed_qty)->toBe(2.0)
        ->and(StockLedger::query()->where('direction', 'out')->count())->toBe(1);
});

test('fulfillment idempotency payload conflict is rejected', function (): void {
    $manager = app(InventoryManager::class);
    $reservation = $manager->reserve(1, 4, 1, 'App\\Models\\SalesOrder', 'SO-KEY-CONFLICT');
    $manager->consume($reservation->getKey(), 1, 'SHIPMENT-SAME');

    expect(fn() => $manager->consume($reservation->getKey(), 2, 'SHIPMENT-SAME'))
        ->toThrow(DomainException::class, 'different payload');
});

test('failed Goods Issue rolls back both stock effects and reservation consumption', function (): void {
    $this->postReceipt(3, externalId: 'AC4-ROLLBACK-GR');
    $manager = app(InventoryManager::class);
    $reservation = $manager->reserve(1, 3, 1, 'App\\Models\\SalesOrder', 'SO-AC4-ROLLBACK');

    expect(fn() => $manager->post(phase4IssueData('AC4-ROLLBACK-GI', 'WRONG-SOURCE', 3, [
        new ReservationConsumptionData($reservation->getKey(), 1, 3, 'SHIPMENT-ROLLBACK'),
    ])))->toThrow(DomainException::class, 'source must match');

    expect(Document::query()->where('external_id', 'AC4-ROLLBACK-GI')->count())->toBe(0)
        ->and(StockLedger::query()->where('direction', 'out')->count())->toBe(0)
        ->and(ReservationConsumption::query()->count())->toBe(0)
        ->and((float) $reservation->refresh()->remaining_qty)->toBe(3.0);
});

test('accounting failure rolls back the linked reservation consumption', function (): void {
    $this->postReceipt(3, externalId: 'AC4-ACCOUNTING-GR');
    $manager = app(InventoryManager::class);
    $reservation = $manager->reserve(1, 3, 1, 'App\\Models\\SalesOrder', 'SO-AC4-ACCOUNTING');
    $accounting = new SpyAccountingBridge();
    $accounting->postException = new DomainException('Accounting unavailable.');
    app()->instance(AccountingBridge::class, $accounting);
    app()->forgetInstance(InventoryManager::class);
    app()->forgetInstance(\ESolution\Inventory\Services\PostingEngine::class);

    expect(fn() => app(InventoryManager::class)->post(phase4IssueData(
        'AC4-ACCOUNTING-GI',
        'SO-AC4-ACCOUNTING',
        3,
        [new ReservationConsumptionData($reservation->getKey(), 1, 3, 'SHIPMENT-ACCOUNTING')],
    )))->toThrow(DomainException::class, 'Accounting unavailable');

    expect(Document::query()->where('external_id', 'AC4-ACCOUNTING-GI')->doesntExist())->toBeTrue()
        ->and(ReservationConsumption::query()->count())->toBe(0)
        ->and((float) $reservation->refresh()->consumed_qty)->toBe(0.0)
        ->and(StockLedger::query()->where('direction', 'out')->count())->toBe(0);
});

test('approval pause defers reservation consumption until approved posting resumes', function (): void {
    $this->postReceipt(3, externalId: 'AC4-APPROVAL-GR');
    $manager = app(InventoryManager::class);
    $reservation = $manager->reserve(1, 3, 1, 'App\\Models\\SalesOrder', 'SO-AC4-APPROVAL');
    app()->instance(ApprovalBridge::class, new SpyApprovalBridge(true));
    app()->forgetInstance(InventoryManager::class);
    app()->forgetInstance(\ESolution\Inventory\Services\PostingEngine::class);

    $document = app(InventoryManager::class)->post(phase4IssueData('AC4-APPROVAL-GI', 'SO-AC4-APPROVAL', 3, [
        new ReservationConsumptionData($reservation->getKey(), 1, 3, 'SHIPMENT-APPROVAL'),
    ]));

    expect($document->status)->toBe(DocumentStatus::WAITING_APPROVAL)
        ->and((float) $reservation->refresh()->consumed_qty)->toBe(0.0)
        ->and(StockLedger::query()->where('direction', 'out')->count())->toBe(0);

    $document->approval_status = 'approved';
    $document->save();

    expect($document->refresh()->status)->toBe(DocumentStatus::POSTED)
        ->and((float) $reservation->refresh()->consumed_qty)->toBe(3.0)
        ->and(StockLedger::query()->where('direction', 'out')->count())->toBe(1);
});

test('AC4-03 release restores availability without stock ledger effects', function (): void {
    $this->postReceipt(8, externalId: 'AC4-03-GR');
    $manager = app(InventoryManager::class);
    $reservation = $manager->reserve(1, 5, 1, 'App\\Models\\SalesOrder', 'SO-AC4-03');
    $beforeLedger = StockLedger::query()->count();

    expect($manager->availability(1, 1)->availableQty())->toBe(3.0);
    $manager->release($reservation->getKey());

    expect($manager->availability(1, 1)->availableQty())->toBe(8.0)
        ->and($reservation->refresh()->status)->toBe('released')
        ->and(StockLedger::query()->count())->toBe($beforeLedger);
});

test('AC4-04 reservation operations never invoke accounting or approval bridges', function (): void {
    $accounting = new SpyAccountingBridge();
    $approval = new SpyApprovalBridge();
    app()->instance(AccountingBridge::class, $accounting);
    app()->instance(ApprovalBridge::class, $approval);
    $manager = app(InventoryManager::class);

    $released = $manager->reserve(1, 2, 1, 'App\\Models\\SalesOrder', 'SO-RELEASE');
    $manager->release($released->getKey());
    $consumed = $manager->reserve(1, 2, 1, 'App\\Models\\SalesOrder', 'SO-CONSUME');
    $manager->consume($consumed->getKey(), 2, 'DIRECT-CONSUME');

    expect($accounting->posts)->toBe(0)
        ->and($accounting->reversals)->toBe(0)
        ->and($approval->checks)->toBe(0)
        ->and(StockLedger::query()->count())->toBe(0);
});

test('AC4-05 repeated partial fulfillment closes only when exhausted', function (): void {
    $this->postReceipt(10, externalId: 'AC4-05-GR');
    $manager = app(InventoryManager::class);
    $reservation = $manager->reserve(1, 10, 1, 'App\\Models\\SalesOrder', 'SO-AC4-05');

    $manager->post(phase4IssueData('AC4-05-GI-1', 'SO-AC4-05', 4, [
        new ReservationConsumptionData($reservation->getKey(), 1, 4, 'SHIPMENT-1'),
    ]));
    expect($reservation->refresh()->status)->toBe('active')
        ->and($reservation->remaining_qty)->toBe(6.0);

    $manager->post(phase4IssueData('AC4-05-GI-2', 'SO-AC4-05', 6, [
        new ReservationConsumptionData($reservation->getKey(), 1, 6, 'SHIPMENT-2'),
    ]));
    expect($reservation->refresh()->status)->toBe('consumed')
        ->and($reservation->remaining_qty)->toBe(0.0)
        ->and($reservation->consumptions()->count())->toBe(2);
});

test('over-consumption rejects and rolls back the Goods Issue', function (): void {
    $this->postReceipt(5, externalId: 'AC4-OVER-GR');
    $manager = app(InventoryManager::class);
    $reservation = $manager->reserve(1, 2, 1, 'App\\Models\\SalesOrder', 'SO-AC4-OVER');

    expect(fn() => $manager->post(phase4IssueData('AC4-OVER-GI', 'SO-AC4-OVER', 3, [
        new ReservationConsumptionData($reservation->getKey(), 1, 3, 'SHIPMENT-OVER'),
    ])))->toThrow(DomainException::class, 'remaining quantity');

    expect(Document::query()->where('external_id', 'AC4-OVER-GI')->doesntExist())->toBeTrue()
        ->and((float) $reservation->refresh()->consumed_qty)->toBe(0.0)
        ->and(StockLedger::query()->where('direction', 'out')->count())->toBe(0);
});

test('AC4-06 walk-in issue works without a prior reservation', function (): void {
    $this->postReceipt(2, externalId: 'AC4-06-GR');
    $document = app(InventoryManager::class)->post(new DocumentData(
        type: 'sales_delivery',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'AC4-06-WALK-IN',
        partyType: 'App\\Models\\Customer',
        partyId: 'WALK-IN',
        lines: [new LineData(1, 1, 1, 2)],
    ));

    expect($document->status)->toBe(DocumentStatus::POSTED)
        ->and(Reservation::query()->count())->toBe(0)
        ->and(StockLedger::query()->where('direction', 'out')->count())->toBe(1);
});

test('AC4-07 Purchasing receipt posts directly without reservation', function (): void {
    $document = app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'AC4-07-PO-RECEIPT',
        sourceType: 'App\\Models\\PurchaseOrder',
        sourceId: 'PO-100',
        partyType: 'App\\Models\\Supplier',
        partyId: 'SUPPLIER-20',
        lines: [new LineData(1, 1, 1, 7, unitCost: 12)],
    ));

    expect($document->status)->toBe(DocumentStatus::POSTED)
        ->and($document->source_type)->toBe('App\\Models\\PurchaseOrder')
        ->and($document->party_type)->toBe('App\\Models\\Supplier')
        ->and(Reservation::query()->count())->toBe(0)
        ->and(StockLedger::query()->where('direction', 'in')->count())->toBe(1);
});

test('reservation availability policy blocks oversubscription when explicitly enabled', function (): void {
    $this->postReceipt(5, externalId: 'AC4-POLICY-GR');
    config()->set('inventory.policies.negative_stock.applies_to', ['goods_issue', 'reservation']);
    $manager = app(InventoryManager::class);
    $manager->reserve(1, 4, 1, 'App\\Models\\SalesOrder', 'SO-POLICY-1');

    expect(fn() => $manager->reserve(1, 2, 1, 'App\\Models\\SalesOrder', 'SO-POLICY-2'))
        ->toThrow(DomainException::class, 'Insufficient available stock');
});

test('stock locks are included in the available quantity calculation', function (): void {
    $this->postReceipt(10, externalId: 'AC4-LOCK-GR');
    DB::table('inv_stock_locks')->insert([
        'item_id' => 1,
        'scope_type' => 'warehouse',
        'scope_id' => 1,
        'locked_qty' => 3,
        'reason' => 'quality_hold',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $availability = app(InventoryManager::class)->availability(1, 1);

    expect($availability->onHandQty)->toBe(10.0)
        ->and($availability->lockedQty)->toBe(3.0)
        ->and($availability->availableQty())->toBe(7.0);
});

test('parallel reservation availability enforcement')->todo(
    'Requires independent MySQL/PostgreSQL connections to prove row-lock serialization.',
);
