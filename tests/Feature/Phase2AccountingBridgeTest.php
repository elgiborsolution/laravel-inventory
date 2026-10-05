<?php

use ESolution\Inventory\Bridges\ExternalAccountingBridge;
use ESolution\Inventory\Bridges\NullAccountingBridge;
use ESolution\Inventory\Bridges\Support\MappingKeyGuard;
use ESolution\Inventory\Bridges\Support\ServiceCodeResolver;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\Contracts\AccountingJournalGateway;
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\CostLayer;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\Inventory\Tests\Fakes\FakeAccountingJournalGateway;
use Illuminate\Support\Facades\DB;

function bindRealAccountingStub(?FakeAccountingJournalGateway $gateway = null): FakeAccountingJournalGateway
{
    $gateway ??= new FakeAccountingJournalGateway();
    config()->set('inventory.accounting.enabled', true);
    app()->instance(AccountingJournalGateway::class, $gateway);
    app()->instance(AccountingBridge::class, new ExternalAccountingBridge(
        $gateway,
        app(ServiceCodeResolver::class),
        app(MappingKeyGuard::class),
    ));

    return $gateway;
}

beforeEach(function (): void {
    $this->installInventorySchema();
});

test('AC2-01 disabled accounting uses Null Bridge and makes no external call', function (): void {
    config()->set('inventory.accounting.enabled', false);
    $gateway = new FakeAccountingJournalGateway();
    app()->instance(AccountingJournalGateway::class, $gateway);

    $this->postReceipt(externalId: 'AC2-01-GR');

    expect(app(AccountingBridge::class))->toBeInstanceOf(NullAccountingBridge::class)
        ->and($gateway->posts)->toBe([]);
});

test('AC2-01 absent optional package uses Null Bridge even when config is enabled', function (): void {
    if (class_exists('ESolution\\LaravelAccounting\\Services\\JournalService')) {
        $this->markTestSkipped('The optional accounting package is installed in this test environment.');
    }

    config()->set('inventory.accounting.enabled', true);

    expect(app(AccountingBridge::class))->toBeInstanceOf(NullAccountingBridge::class);
    expect($this->postReceipt(externalId: 'AC2-01-ABSENT')->status->value)->toBe('posted');
});

test('AC2-02 AC2-03 AC2-04 and AC2-05 forward mapped cost caller lines and tenant', function (): void {
    $gateway = bindRealAccountingStub();
    $document = app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'AC2-02-GR',
        lines: [new LineData(1, 1, 1, 10, qtyBonus: 2, unitCost: 6)],
        additionalJournalLines: [
            ['mapping_key' => 'purchase_credit_ap_k', 'amount' => 60, 'description' => 'Caller AP'],
        ],
        tenantIdentity: 'tenant-77',
    ));

    $call = $gateway->posts[0];
    expect($call['payload']['service_code'])->toBe('PURCHASE_CREDIT')
        ->and($call['payload']['source_type'])->toBe($document->getMorphClass())
        ->and($call['payload']['source_id'])->toBe($document->getKey())
        ->and($call['payload']['items'][0])->toMatchArray([
            'mapping_key' => 'purchase_credit_ap_k',
            'amount' => 60,
            'description' => 'Caller AP',
        ])
        ->and($call['payload']['items'][1])->toMatchArray([
            'mapping_key' => 'purchase_credit_inventory_d',
            'amount' => 60.0,
        ])
        ->and($call['tenant'])->toBe('tenant-77');
});

test('AC2-06 external exception rolls back document ledger cost and Stock Card', function (): void {
    $gateway = new FakeAccountingJournalGateway();
    $gateway->postException = new RuntimeException('Accounting period is locked.', 423);
    bindRealAccountingStub($gateway);

    expect(fn() => $this->postReceipt(externalId: 'AC2-06-LOCKED'))
        ->toThrow(RuntimeException::class, 'period is locked');
    expect(Document::query()->count())->toBe(0)
        ->and(DB::table('inv_stock_ledgers')->count())->toBe(0)
        ->and(DB::table('inv_cost_layers')->count())->toBe(0)
        ->and(DB::table('inv_stock_cards')->count())->toBe(0);
});

test('external dynamic-account validation errors propagate and roll back posting', function (): void {
    $gateway = new FakeAccountingJournalGateway();
    $gateway->postException = new DomainException('Dynamic mapping requires account_id.');
    bindRealAccountingStub($gateway);

    expect(fn() => $this->postReceipt(externalId: 'AC2-DYNAMIC-MISSING'))
        ->toThrow(DomainException::class, 'requires account_id');
    expect(Document::query()->count())->toBe(0)
        ->and(DB::table('inv_stock_ledgers')->count())->toBe(0);
});

test('external required mapping-key errors propagate and roll back posting', function (): void {
    $gateway = new FakeAccountingJournalGateway();
    $gateway->postException = new DomainException('Required mapping key is missing.');
    bindRealAccountingStub($gateway);

    expect(fn() => $this->postReceipt(externalId: 'AC2-REQUIRED-MISSING'))
        ->toThrow(DomainException::class, 'Required mapping key');
    expect(Document::query()->count())->toBe(0)
        ->and(DB::table('inv_stock_ledgers')->count())->toBe(0);
});

test('AC2-08 missing mapping fails closed before the external call', function (): void {
    $gateway = bindRealAccountingStub();
    app(\ESolution\Inventory\Contracts\DocumentTypeRegistry::class)
        ->register('unmapped_receipt', new \ESolution\Inventory\Support\DocumentTypeDefinition('in'));

    expect(fn() => app(InventoryManager::class)->post(new DocumentData(
        type: 'unmapped_receipt',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'AC2-08-MISSING',
        lines: [new LineData(1, 1, 1, 1, unitCost: 5)],
    )))->toThrow(DomainException::class, 'mapping is missing');
    expect($gateway->posts)->toBe([])
        ->and(Document::query()->count())->toBe(0);
});

test('AC2-09 unsafe caller mapping key is rejected before external call', function (): void {
    $gateway = bindRealAccountingStub();

    expect(fn() => app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'AC2-09-INJECTION',
        lines: [new LineData(1, 1, 1, 1, unitCost: 5)],
        additionalJournalLines: [['mapping_key' => 'unrelated_cash_k', 'amount' => 5]],
    )))->toThrow(DomainException::class, 'must start with');
    expect($gateway->posts)->toBe([])
        ->and(Document::query()->count())->toBe(0);
});

test('explicit null mapping skips intra-company accounting call', function (): void {
    config()->set('inventory.accounting.service_code_map.internal_movement', null);
    app(\ESolution\Inventory\Contracts\DocumentTypeRegistry::class)
        ->register('internal_movement', new \ESolution\Inventory\Support\DocumentTypeDefinition('none', costing: false));
    $gateway = bindRealAccountingStub();

    app(InventoryManager::class)->post(new DocumentData(
        type: 'internal_movement',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'AC2-TRANSFER-SKIP',
        lines: [new LineData(1, 1, 1, 1)],
    ));

    expect($gateway->posts)->toBe([]);
});

test('caller-selected sales service code must be allowed by project mapping', function (): void {
    $gateway = bindRealAccountingStub();
    CostLayer::create([
        'item_id' => 1,
        'scope_type' => 'warehouse',
        'scope_id' => 1,
        'received_qty' => 10,
        'remaining_qty' => 10,
        'unit_cost' => 5,
        'received_at' => now(),
    ]);

    app(InventoryManager::class)->post(new DocumentData(
        type: 'goods_issue',
        organizationId: 1,
        trxDate: '2026-09-01',
        externalId: 'AC2-SALES',
        lines: [new LineData(1, 1, 1, 2)],
        additionalJournalLines: [['mapping_key' => 'sales_credit_revenue_k', 'amount' => 20]],
        accountingServiceCode: 'SALES_CREDIT',
    ));

    expect($gateway->posts[0]['payload']['items'])->toContain(
        ['mapping_key' => 'sales_credit_cogs_d', 'amount' => 10.0],
        ['mapping_key' => 'sales_credit_inventory_k', 'amount' => 10.0],
    );

    expect(fn() => app(ServiceCodeResolver::class)->resolve('goods_issue', 'UNAPPROVED_CODE'))
        ->toThrow(DomainException::class, 'is not allowed');
});

test('AC2-07 reversal delegates to original external journal and rejects duplicate reversal', function (): void {
    $gateway = new FakeAccountingJournalGateway();
    $gateway->originalJournalId = 'journal-original-10';
    $bridge = new ExternalAccountingBridge($gateway, app(ServiceCodeResolver::class), app(MappingKeyGuard::class));
    $original = Document::create([
        'document_type' => 'purchase_receipt',
        'organization_id' => 1,
        'source_type' => 'inventory',
        'trx_date' => '2026-09-01',
        'status' => 'posted',
        'meta' => ['_accounting_context' => ['tenant_identity' => 'tenant-77']],
    ]);

    $bridge->reverse($original, 'Manual correction');

    expect($gateway->reversals)->toBe([[
        'journal_id' => 'journal-original-10',
        'reason' => 'Manual correction',
        'tenant' => 'tenant-77',
    ]]);
    expect(fn() => $bridge->reverse($original, 'Duplicate'))
        ->toThrow(RuntimeException::class, 'already been reversed');
});

test('accounting validation command succeeds when accounting is disabled', function (): void {
    config()->set('inventory.accounting.enabled', false);

    $this->artisan('inventory:accounting:validate')
        ->expectsOutput('Inventory accounting is disabled; Null Accounting Bridge is active.')
        ->assertSuccessful();
});

test('accounting validation command fails when enabled package is absent', function (): void {
    if (class_exists('ESolution\\LaravelAccounting\\Services\\JournalService')) {
        $this->markTestSkipped('The optional accounting package is installed in this test environment.');
    }

    config()->set('inventory.accounting.enabled', true);

    $this->artisan('inventory:accounting:validate')->assertFailed();
});

test('AC2-09 Core owns no internal journal engine or schema', function (): void {
    expect(class_exists('ESolution\\Inventory\\Services\\JournalManager'))->toBeFalse()
        ->and(class_exists('ESolution\\Inventory\\Models\\Journal'))->toBeFalse();

    foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') ?: [] as $migration) {
        expect(file_get_contents($migration))->not->toContain('inv_journal', 'acc_');
    }
});
