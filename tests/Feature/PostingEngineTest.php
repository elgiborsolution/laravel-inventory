<?php

use ESolution\Inventory\Bridges\ExternalApprovalBridge;
use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Enums\DocumentStatus;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\Inventory\Services\WorkflowEngine;
use ESolution\Inventory\Tests\Fakes\FakeApprovalWorkflowGateway;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->artisan('migrate', ['--database' => 'testing'])->assertSuccessful();

    DB::table('inv_organizations')->insert([
        'id' => 1,
        'type' => 'warehouse',
        'code' => 'WH-01',
        'name' => 'Main Warehouse',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('inv_item_categories')->insert([
        'id' => 1,
        'code' => 'GENERAL',
        'name' => 'General',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('inv_uoms')->insert([
        'id' => 1,
        'code' => 'PCS',
        'name' => 'Pieces',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('inv_items')->insert([
        [
            'id' => 1,
            'sku' => 'STOCK-1',
            'name' => 'Stock Item',
            'item_type' => 'stock',
            'item_category_id' => 1,
            'base_uom_id' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 2,
            'sku' => 'SERVICE-1',
            'name' => 'Service Item',
            'item_type' => 'service',
            'item_category_id' => 1,
            'base_uom_id' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
});

it('posts FIFO receipt and issue with immutable ledger effects', function (): void {
    $manager = app(InventoryManager::class);
    $receipt = $manager->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'GR-001',
        lines: [new LineData(1, 1, 1, 10, unitCost: 5)],
    ));
    $issue = $manager->post(new DocumentData(
        type: 'goods_issue',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'GI-001',
        lines: [new LineData(1, 1, 1, 4)],
    ));

    expect($receipt->status)->toBe(DocumentStatus::POSTED)
        ->and($issue->status)->toBe(DocumentStatus::POSTED)
        ->and((float) DB::table('inv_cost_layers')->value('remaining_qty'))->toBe(6.0)
        ->and((float) DB::table('inv_stock_cards')->value('running_qty'))->toBe(6.0)
        ->and(StockLedger::query()->count())->toBe(2);

    expect(fn() => StockLedger::query()->firstOrFail()->forceFill(['qty' => 99])->save())
        ->toThrow(LogicException::class);
});

it('returns the existing document for an identical idempotent retry and rejects a conflict', function (): void {
    $manager = app(InventoryManager::class);
    $data = new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'GR-IDEMPOTENT',
        lines: [new LineData(1, 1, 1, 2, unitCost: 7)],
    );

    $first = $manager->post($data);
    $retry = $manager->post($data);

    expect($retry->getKey())->toBe($first->getKey())
        ->and(Document::query()->count())->toBe(1)
        ->and(StockLedger::query()->count())->toBe(1);

    expect(fn() => $manager->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'GR-IDEMPOTENT',
        lines: [new LineData(1, 1, 1, 3, unitCost: 7)],
    )))->toThrow(DomainException::class, 'different payload');
});

it('does not write stock for service lines', function (): void {
    $document = app(InventoryManager::class)->post(new DocumentData(
        type: 'goods_issue',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'SERVICE-001',
        lines: [new LineData(2, 1, 1, 1)],
    ));

    expect($document->status)->toBe(DocumentStatus::POSTED)
        ->and(StockLedger::query()->count())->toBe(0);
});

it('reserves, partially consumes, retries consumption, and releases without ledger writes', function (): void {
    $manager = app(InventoryManager::class);
    $reservation = $manager->reserve(1, 10, 1, 'sales_order', 'SO-001');
    $reservation = $manager->consume($reservation->getKey(), 4, 'FULFILL-001');
    $reservation = $manager->consume($reservation->getKey(), 4, 'FULFILL-001');
    $reservation = $manager->release($reservation->getKey(), 2);

    expect((float) $reservation->consumed_qty)->toBe(4.0)
        ->and((float) $reservation->released_qty)->toBe(2.0)
        ->and($reservation->remaining_qty)->toBe(4.0)
        ->and(StockLedger::query()->count())->toBe(0);
});

it('requires last-known cost for negative stock and settles it on the next receipt', function (): void {
    config()->set('inventory.policies.negative_stock.mode', 'allow');
    $manager = app(InventoryManager::class);

    expect(fn() => $manager->post(new DocumentData(
        type: 'goods_issue',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'GI-NO-HISTORY',
        lines: [new LineData(1, 1, 1, 1)],
    )))->toThrow(DomainException::class, 'last-known cost');

    $manager->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'GR-COST-HISTORY',
        lines: [new LineData(1, 1, 1, 1, unitCost: 10)],
    ));
    $manager->post(new DocumentData(
        type: 'goods_issue',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'GI-ZERO',
        lines: [new LineData(1, 1, 1, 1)],
    ));
    $manager->post(new DocumentData(
        type: 'goods_issue',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'GI-NEGATIVE',
        lines: [new LineData(1, 1, 1, 2)],
    ));
    $manager->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'GR-SETTLE',
        lines: [new LineData(1, 1, 1, 2, unitCost: 12)],
    ));

    expect((float) DB::table('inv_cost_adjustments')->value('settled_qty'))->toBe(2.0)
        ->and((float) DB::table('inv_cost_adjustments')->value('amount_delta'))->toBe(4.0)
        ->and((float) DB::table('inv_stock_cards')->value('running_qty'))->toBe(0.0)
        ->and((float) DB::table('inv_stock_cards')->value('running_value'))->toBe(0.0);
});

it('resumes an approved document exactly once when delivery is repeated', function (): void {
    $approval = new FakeApprovalWorkflowGateway();
    $approval->requireApproval();
    app()->instance(ApprovalBridge::class, new ExternalApprovalBridge($approval));
    $manager = app(InventoryManager::class);
    $document = $manager->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'GR-APPROVED',
        lines: [new LineData(1, 1, 1, 3, unitCost: 9)],
    ));

    expect($document->status)->toBe(DocumentStatus::WAITING_APPROVAL)
        ->and(StockLedger::query()->count())->toBe(0);

    app(WorkflowEngine::class)->transition($document, DocumentStatus::APPROVED);
    $firstResume = $manager->resumeApproved($document->getKey());
    $duplicateResume = $manager->resumeApproved($document->getKey());

    expect($firstResume->status)->toBe(DocumentStatus::POSTED)
        ->and($duplicateResume->status)->toBe(DocumentStatus::POSTED)
        ->and($duplicateResume->posting_completed_at)->not->toBeNull()
        ->and(StockLedger::query()->count())->toBe(1);
});
