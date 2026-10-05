<?php

use ESolution\Inventory\Bridges\ExternalApprovalBridge;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\ApprovalBridge;
use ESolution\Inventory\Contracts\DocumentTypeRegistry;
use ESolution\Inventory\DTO\AccountingPostingData;
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Enums\DocumentStatus;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\ConfigurationDepthResolver;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\Inventory\Support\DocumentTypeDefinition;
use ESolution\Inventory\Tests\Fakes\FakeApprovalWorkflowGateway;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->installInventorySchema();
});

test('AC-01 FIFO receipt and issue update quantity and value', function (): void {
    $this->postReceipt(10, 5, externalId: 'AC01-GR');
    $this->postIssue(4, externalId: 'AC01-GI');

    expect((float) DB::table('inv_stock_cards')->value('running_qty'))->toBe(6.0)
        ->and((float) DB::table('inv_stock_cards')->value('running_value'))->toBe(30.0);
});

test('AC-01 Weighted Average posts through the Posting Engine')->todo('Driver exists, but PostingEngine driver selection is not implemented.');
test('AC-01 Moving Average posts through the Posting Engine')->todo('Driver exists, but PostingEngine driver selection is not implemented.');

test('AC-02 organization and storage depth retain mandatory minimums', function (): void {
    $resolver = app(ConfigurationDepthResolver::class);
    $valid = config('inventory');
    $invalid = $valid;
    $invalid['storage']['levels']['rack'] = false;

    expect($resolver->validate($valid))->toBe([])
        ->and($resolver->validate($invalid))->toContain('Storage warehouse and rack levels are mandatory.');
});

test('AC-03 disabling a configured level does not delete historical rows', function (): void {
    DB::table('inv_storage_locations')->insert([
        'id' => 10,
        'organization_id' => 1,
        'type' => 'rack',
        'code' => 'R-01',
        'name' => 'Rack 01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    config()->set('inventory.storage.levels.rack', false);

    expect(DB::table('inv_storage_locations')->where('id', 10)->exists())->toBeTrue();
});

test('AC-04 posted ledger entries reject mutation', function (): void {
    $this->postReceipt(externalId: 'AC04-GR');

    expect(fn() => StockLedger::query()->firstOrFail()->forceFill(['qty' => 99])->save())
        ->toThrow(LogicException::class);
});

test('AC-04 correction is represented by a linked reversal document', function (): void {
    $receipt = $this->postReceipt();
    $reversal = app(InventoryManager::class)->reverse(new \ESolution\Inventory\DTO\ReversalRequest($receipt->id, 'Correction'));
    expect((int) $reversal->reversal_of_id)->toBe((int) $receipt->id)
        ->and($reversal->reversal_reason)->toBe('Correction')
        ->and($receipt->fresh()->status->value)->toBe('reversed');
});

test('AC-05 document creation is idempotent and rejects payload conflict', function (): void {
    $first = $this->postReceipt(2, 7, externalId: 'AC05-GR');
    $retry = $this->postReceipt(2, 7, externalId: 'AC05-GR');

    expect($retry->getKey())->toBe($first->getKey())
        ->and(Document::query()->count())->toBe(1);

    expect(fn() => $this->postReceipt(3, 7, externalId: 'AC05-GR'))
        ->toThrow(DomainException::class, 'different payload');
});

test('AC-06 party and source references work without external foreign keys', function (): void {
    $document = app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-08-31',
        lines: [new LineData(1, 1, 1, 1, unitCost: 5)],
        externalId: 'AC06-GR',
        sourceType: 'purchase_order',
        sourceId: 'PO-EXTERNAL-99',
        partyType: 'supplier',
        partyId: 'SUP-EXTERNAL-10',
    ));

    expect($document->source_type)->toBe('purchase_order')
        ->and($document->source_id)->toBe('PO-EXTERNAL-99')
        ->and($document->party_type)->toBe('supplier')
        ->and($document->party_id)->toBe('SUP-EXTERNAL-10');
});

test('AC-07 service lines do not affect stock ledger', function (): void {
    app(InventoryManager::class)->post(new DocumentData(
        type: 'goods_issue',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'AC07-SERVICE',
        lines: [new LineData(2, 1, 1, 1)],
    ));

    expect(StockLedger::query()->count())->toBe(0);
});

test('AC-08 costing scope changes grouping without schema changes', function (): void {
    DB::table('inv_storage_locations')->insert([
        'id' => 10,
        'organization_id' => 1,
        'type' => 'rack',
        'code' => 'R-01',
        'name' => 'Rack 01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    config()->set('inventory.costing.scope', 'rack');

    $this->postReceipt(locationId: 10, externalId: 'AC08-GR');

    expect(DB::table('inv_stock_cards')->value('scope_type'))->toBe('rack')
        ->and((int) DB::table('inv_stock_cards')->value('scope_id'))->toBe(10);
});

test('AC-09 batch and serial ownership mismatch is rejected', function (): void {
    DB::table('inv_batches')->insert([
        'id' => 20,
        'item_id' => 2,
        'batch_no' => 'WRONG-ITEM',
        'status' => 'available',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn() => app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'AC09-GR',
        lines: [new LineData(1, 1, 1, 1, unitCost: 5, batchId: 20)],
    )))->toThrow(DomainException::class, 'different item');
});

test('AC-09 tracking is enforced only when configured')->todo('Tracking requirement resolution from item/category policy is not implemented.');
test('AC-10 certificate requirements block invalid posting')->todo('Certificate policy validator is not implemented.');

test('AC-11 purchase bonus quantity blends cost', function (): void {
    $this->postReceipt(10, 6, 2, 'AC11-GR');

    expect((float) DB::table('inv_cost_layers')->value('received_qty'))->toBe(12.0)
        ->and((float) DB::table('inv_cost_layers')->value('unit_cost'))->toBe(5.0)
        ->and((float) DB::table('inv_stock_ledgers')->value('amount'))->toBe(60.0);
});

test('AC-12 sale bonus consumes total quantity and remains reportable', function (): void {
    $this->postReceipt(12, 5, externalId: 'AC12-GR');
    $this->postIssue(10, 2, 'AC12-GI');
    $issueLedger = DB::table('inv_stock_ledgers')->where('direction', 'out')->first();

    expect((float) $issueLedger->qty)->toBe(12.0)
        ->and((float) $issueLedger->qty_bonus)->toBe(2.0)
        ->and((float) DB::table('inv_stock_cards')->value('running_qty'))->toBe(0.0);
});

test('AC-13 certificates cannot attach without a valid tracking identity')->todo('Portable tracking-identity constraint is not implemented.');
test('AC-14 reorder notification is deduplicated and creates no purchase document')->todo('Reorder notification service is not implemented.');

test('AC-15 negative stock requires history and settles at actual receipt cost', function (): void {
    expect(fn() => $this->postIssue(1, externalId: 'AC15-BLOCKED'))
        ->toThrow(DomainException::class, 'Insufficient stock');

    config()->set('inventory.policies.negative_stock.mode', 'allow');
    $this->postReceipt(1, 10, externalId: 'AC15-HISTORY');
    $this->postIssue(1, externalId: 'AC15-ZERO');
    $this->postIssue(2, externalId: 'AC15-NEGATIVE');
    $this->postReceipt(2, 12, externalId: 'AC15-SETTLE');

    expect((float) DB::table('inv_cost_adjustments')->value('settled_qty'))->toBe(2.0)
        ->and((float) DB::table('inv_cost_adjustments')->value('amount_delta'))->toBe(4.0);
});

test('AC-16 locks and freeze prevent prohibited movement at scope')->todo('Stock-lock and freeze enforcement is not implemented.');

test('AC-17 custom document types register without patching Core', function (): void {
    app(DocumentTypeRegistry::class)->register('custom_receipt', new DocumentTypeDefinition('in'));
    $document = app(InventoryManager::class)->post(new DocumentData(
        type: 'custom_receipt',
        organizationId: 1,
        trxDate: '2026-08-31',
        externalId: 'AC17-CUSTOM',
        lines: [new LineData(1, 1, 1, 1, unitCost: 5)],
    ));

    expect($document->status)->toBe(DocumentStatus::POSTED);
});

test('AC-18 posting proceeds when approval is disabled', function (): void {
    config()->set('inventory.approval.enabled', false);

    expect($this->postReceipt(externalId: 'AC18-GR')->status)->toBe(DocumentStatus::POSTED);
});

test('AC-19 posting proceeds when accounting is disabled', function (): void {
    config()->set('inventory.accounting.enabled', false);

    expect($this->postReceipt(externalId: 'AC19-GR')->status)->toBe(DocumentStatus::POSTED);
});

test('AC-20 accounting failure rolls back stock posting', function (): void {
    app()->instance(AccountingBridge::class, new class implements AccountingBridge {
        public function post(Document $document, AccountingPostingData $data): ?string
        {
            throw new DomainException('Stub accounting failure.');
        }

        public function reverse(Document $originalDocument, string $reason): void {}
    });

    expect(fn() => $this->postReceipt(externalId: 'AC20-GR'))
        ->toThrow(DomainException::class, 'Stub accounting failure');
    expect(Document::query()->count())->toBe(0)
        ->and(StockLedger::query()->count())->toBe(0)
        ->and(DB::table('inv_cost_layers')->count())->toBe(0);
});

test('AC-21 Core document status remains separate from approval status', function (): void {
    $approval = new FakeApprovalWorkflowGateway();
    $approval->requireApproval();
    app()->instance(ApprovalBridge::class, new ExternalApprovalBridge($approval));
    $document = $this->postReceipt(externalId: 'AC21-GR');

    expect($document->status)->toBe(DocumentStatus::WAITING_APPROVAL)
        ->and($document->approval_status)->toBe('pending_approval');
});

test('AC-22 domain events emit at documented lifecycle points')->todo('After-commit domain events are not implemented.');

test('AC-23 reservation changes availability state without ledger effects', function (): void {
    $manager = app(InventoryManager::class);
    $reservation = $manager->reserve(1, 10, 1, 'sales_order', 'AC23-SO');
    $reservation = $manager->consume($reservation->getKey(), 3, 'AC23-FULFILL');
    $reservation = $manager->release($reservation->getKey(), 2);

    expect($reservation->remaining_qty)->toBe(5.0)
        ->and(StockLedger::query()->count())->toBe(0)
        ->and(DB::table('inv_cost_layers')->count())->toBe(0);
});
