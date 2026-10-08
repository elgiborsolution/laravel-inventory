<?php

use ESolution\Inventory\Bridges\ExternalAccountingBridge;
use ESolution\Inventory\Bridges\Support\MappingKeyGuard;
use ESolution\Inventory\Bridges\Support\ServiceCodeResolver;
use ESolution\Inventory\Contracts\AccountingBridge;
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\Inventory\Tests\Fakes\FakeAccountingJournalGateway;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->installInventorySchema();
    config(['inventory.costing.default_method' => 'moving_average']);
});

function businessPost(string $type, string $key, float $qty, ?float $cost = null, ?float $price = null, float $discount = 0): Document
{
    return app(InventoryManager::class)->post(new DocumentData(
        $type,
        1,
        '2026-10-01',
        [new LineData(1, 1, 1, $qty, unitCost: $cost, transactionPrice: $price, discountPerUnit: $discount)],
        externalId: $key,
    ));
}

it('matches the business example through posting and reports transaction profit', function (): void {
    businessPost('purchase', 'PI-001', 10, price: 42000, discount: 2000);
    businessPost('purchase', 'PI-002', 10, price: 50000);
    $sale = businessPost('sale', 'SI-001', 5, price: 60000);
    $retry = businessPost('sale', 'SI-001', 5, price: 60000);
    $rows = app(InventoryManager::class)->stockCard(1, 1);
    expect($retry->id)->toBe($sale->id)->and($rows)->toHaveCount(3)
        ->and($rows[0]['discount_amount'])->toEqual(2000)
        ->and($rows[1]['balance_amount'])->toBe(900000.0)
        ->and($rows[2])->toMatchArray([
            'balance_qty' => 15.0, 'balance_amount' => 675000.0,
            'cogs' => 225000.0, 'total_trx' => 300000.0, 'profit_amount' => 75000.0,
            'profit_unit' => 15000.0, 'average_cost' => 0.0, 'balance_average_cost' => 45000.0,
            'running_total_sales' => 300000.0, 'running_avg_sales' => 60000.0,
        ]);
    expect((float) DB::table('inv_stock_cards')->value('running_value'))->toBe(675000.0);
});

it('recalculates average after an intervening issue and clears value on depletion', function (): void {
    businessPost('purchase', 'P1', 10, 40000);
    businessPost('purchase', 'P2', 10, 50000);
    businessPost('sale', 'S1', 15, price: 60000);
    businessPost('purchase', 'P3', 5, 55000);
    businessPost('sale', 'S2', 10, price: 60000);
    $rows = app(InventoryManager::class)->stockCard(1, 1);
    expect($rows[2]['cogs'])->toBe(675000.0)
        ->and($rows[3]['balance_average_cost'])->toBe(50000.0)
        ->and($rows[4]['cogs'])->toBe(500000.0)
        ->and($rows[4]['balance_qty'])->toBe(0.0)
        ->and($rows[4]['balance_amount'])->toBe(0.0);
});

it('keeps FIFO as the default and respects item method overrides', function (): void {
    config(['inventory.costing.default_method' => 'fifo']);
    DB::table('inv_items')->where('id', 1)->update(['costing_method' => 'moving_average']);
    businessPost('purchase', 'P1', 10, 40000);
    businessPost('purchase', 'P2', 10, 50000);
    businessPost('sale', 'S1', 5);
    expect(app(InventoryManager::class)->stockCard(1, 1)[2]['cogs'])->toBe(225000.0);
});

it('values supplier returns at book cost without recognizing sales profit', function (): void {
    businessPost('purchase', 'P1', 10, 40000);
    businessPost('purchase', 'P2', 10, 50000);
    businessPost('supplier_return', 'R1', 5, price: 50000);
    $row = app(InventoryManager::class)->stockCard(1, 1)[2];
    expect($row)->toMatchArray(['out_amount' => 225000.0, 'total_trx' => 250000.0, 'profit_amount' => 0.0, 'running_total_sales' => 0.0]);
});

it('posts count loss gain zero variance and zero physical stock idempotently', function (): void {
    businessPost('purchase', 'P1', 10, 40000);
    businessPost('purchase', 'P2', 10, 50000);
    $count = businessPost('stock_opname', 'C1', 15);
    businessPost('stock_opname', 'C1', 15);
    expect($count->lines->first()->meta['_stock_count'])->toMatchArray(['counted_qty' => 15, 'system_qty' => 20, 'difference' => -5]);
    businessPost('stock_count', 'C2', 17);
    $unchanged = businessPost('stock_count', 'C3', 17);
    expect(StockLedger::whereIn('document_line_id', $unchanged->lines()->select('id'))->count())->toBe(0);
    businessPost('stock_count', 'C4', 0);
    $rows = app(InventoryManager::class)->stockCard(1, 1);
    expect($rows)->toHaveCount(5)->and($rows[2]['balance_amount'])->toBe(675000.0)
        ->and($rows[3]['balance_amount'])->toBe(765000.0)
        ->and($rows[4]['balance_amount'])->toBe(0.0)
        ->and($rows[4]['balance_qty'])->toBe(0.0);
});

it('rejects unsupported count inputs without partial writes', function (): void {
    $data = new DocumentData('stock_count', 1, '2026-10-01', [new LineData(1, 1, 1, 2), new LineData(1, 1, 1, 3)], externalId: 'DUP');
    expect(fn() => app(InventoryManager::class)->post($data))->toThrow(DomainException::class, 'duplicate');
    expect(fn() => businessPost('stock_count', 'NO-COST', 3))->toThrow(DomainException::class, 'requires unitCost');
    expect(Document::count())->toBe(0)->and(StockLedger::count())->toBe(0);
});

it('blocks negative moving-average stock and rolls back the whole posting', function (): void {
    config(['inventory.policies.negative_stock.mode' => 'allow']);
    businessPost('purchase', 'P1', 10, 40000);
    expect(fn() => businessPost('sale', 'S1', 15))->toThrow(DomainException::class);
    expect(Document::count())->toBe(1)->and((float) DB::table('inv_stock_cards')->value('running_value'))->toBe(400000.0);
});

it('does not invent profit for legacy sales without pricing', function (): void {
    businessPost('purchase', 'P1', 10, 40000);
    businessPost('sale', 'S1', 2);
    businessPost('sale', 'S2', 2, price: 60000);
    $rows = app(InventoryManager::class)->stockCard(1, 1);
    expect($rows[1]['profit_amount'])->toBeNull()->and($rows[2]['profit_amount'])->toBe(40000.0)
        ->and($rows[2]['running_total_sales'])->toBeNull();
});

it('maps supplier refund and gain using verified caller-owned mapping keys', function (): void {
    $gateway = new FakeAccountingJournalGateway();
    app()->instance(AccountingBridge::class, new ExternalAccountingBridge($gateway, app(ServiceCodeResolver::class), app(MappingKeyGuard::class)));
    config(['inventory.accounting.document_mapping_keys.supplier_return' => [
        'payable_debit' => 'purchase_return_ap_d', 'inventory_credit' => 'purchase_return_inventory_k',
        'gain_credit' => 'purchase_return_gain_k', 'loss_debit' => 'purchase_return_loss_d',
    ]]);
    businessPost('purchase', 'P1', 10, 40000);
    businessPost('purchase', 'P2', 10, 50000);
    businessPost('supplier_return', 'R1', 5, price: 50000);
    expect($gateway->posts[2]['payload']['items'])->toBe([
        ['mapping_key' => 'purchase_return_ap_d', 'amount' => 250000.0],
        ['mapping_key' => 'purchase_return_inventory_k', 'amount' => 225000.0],
        ['mapping_key' => 'purchase_return_gain_k', 'amount' => 25000.0],
    ]);
});

it('requires explicit adjustment journal mappings and rolls back on missing roles', function (): void {
    businessPost('purchase', 'P1', 10, 40000);
    $gateway = new FakeAccountingJournalGateway();
    app()->instance(AccountingBridge::class, new ExternalAccountingBridge($gateway, app(ServiceCodeResolver::class), app(MappingKeyGuard::class)));
    // InventoryManager resolved above caches its posting engine: rebuild it for the bridge change.
    app()->forgetInstance(\ESolution\Inventory\Services\PostingEngine::class);
    app()->forgetInstance(InventoryManager::class);
    config(['inventory.accounting.service_code_map.stock_count' => 'STOCK_COUNT']);
    expect(fn() => businessPost('stock_count', 'C1', 8))->toThrow(DomainException::class, 'mapping role');
    expect(Document::count())->toBe(1)->and((float) DB::table('inv_stock_cards')->value('running_qty'))->toBe(10.0);
    config(['inventory.accounting.document_mapping_keys.stock_count' => ['loss_debit' => 'stock_count_loss_d', 'inventory_credit' => 'stock_count_inventory_k']]);
    businessPost('stock_count', 'C1', 8);
    expect($gateway->posts[0]['payload']['items'])->toBe([
        ['mapping_key' => 'stock_count_loss_d', 'amount' => 80000.0],
        ['mapping_key' => 'stock_count_inventory_k', 'amount' => 80000.0],
    ]);
});


it('keeps warehouse average and physical rack balances separate', function (): void {
    DB::table('inv_storage_locations')->insert([
        ['id' => 10, 'organization_id' => 1, 'type' => 'rack', 'code' => 'A', 'name' => 'A'],
        ['id' => 11, 'organization_id' => 1, 'type' => 'rack', 'code' => 'B', 'name' => 'B'],
    ]);
    $manager = app(InventoryManager::class);
    $manager->post(new DocumentData('purchase', 1, '2026-10-01', [new LineData(1, 1, 1, 10, 10, unitCost: 40000)], externalId: 'A'));
    $manager->post(new DocumentData('purchase', 1, '2026-10-01', [new LineData(1, 1, 1, 10, 11, unitCost: 50000)], externalId: 'B'));
    $manager->post(new DocumentData('stock_count', 1, '2026-10-01', [new LineData(1, 1, 1, 8, 11)], externalId: 'COUNT-B'));
    expect($manager->stockCard(1, 1)[2])->toMatchArray(['qty' => 2.0,'cogs' => 90000.0,'balance_qty' => 18.0]);
    expect(fn() => $manager->post(new DocumentData('sale', 1, '2026-10-01', [new LineData(1, 1, 1, 9, 11)], externalId: 'TOO-MUCH-B')))
        ->toThrow(DomainException::class, 'storage location');
});

it('isolates moving average by rack scope', function (): void {
    config(['inventory.costing.scope' => 'rack']);
    DB::table('inv_storage_locations')->insert([
        ['id' => 10, 'organization_id' => 1, 'type' => 'rack', 'code' => 'A', 'name' => 'A'],
        ['id' => 11, 'organization_id' => 1, 'type' => 'rack', 'code' => 'B', 'name' => 'B'],
    ]);
    $manager = app(InventoryManager::class);
    foreach ([[10,40000,'A'],[10,50000,'B'],[11,100000,'C']] as [$rack,$cost,$key]) {
        $manager->post(new DocumentData('purchase', 1, '2026-10-01', [new LineData(1, 1, 1, 10, $rack, unitCost: $cost)], externalId: $key));
    }
    $manager->post(new DocumentData('sale', 1, '2026-10-01', [new LineData(1, 1, 1, 5, 10)], externalId: 'S'));
    expect($manager->stockCard(1, 1, 10)[2]['cogs'])->toBe(225000.0)
        ->and($manager->stockCard(1, 1, 11)[0]['balance_amount'])->toBe(1000000.0);
});

it('absorbs rounding and includes free units without duplicating revenue', function (): void {
    $manager = app(InventoryManager::class);
    $manager->post(new DocumentData('purchase', 1, '2026-10-01', [new LineData(1, 1, 1, 2, qtyBonus: 1, unitCost: 0.5)], externalId: 'P'));
    businessPost('sale', 'S1', 1, price: 1);
    businessPost('sale', 'S2', 1, price: 1);
    businessPost('sale', 'S3', 1, price: 1);
    $rows = $manager->stockCard(1, 1);
    expect($rows[0]['total_trx'])->toBe(1.0)->and($rows[3]['balance_amount'])->toBe(0.0)
        ->and(array_sum(array_column($rows, 'cogs')))->toBe(1.0);
});

it('retains the legacy idempotency payload when no pricing fields are provided', function (): void {
    $data = new DocumentData('purchase_receipt', 1, '2026-10-01', [new LineData(1, 1, 1, 10, unitCost: 4)], externalId: 'LEGACY');
    $document = app(InventoryManager::class)->post($data);
    $payload = get_object_vars($data);
    $oldLine = get_object_vars($data->lines[0]);
    unset($oldLine['transactionPrice'],$oldLine['discountPerUnit']);
    $payload['lines'] = [$oldLine];
    expect($document->idempotency_hash)->toBe(hash('sha256', json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)))
        ->and(json_decode(json_encode($data->lines[0]), true))->not->toHaveKey('transactionPrice');
});

it('waits for approval before applying a stock count and resumes only once', function (): void {
    $gateway = new \ESolution\Inventory\Tests\Fakes\FakeApprovalWorkflowGateway();
    app()->instance(\ESolution\Inventory\Contracts\ApprovalBridge::class, new \ESolution\Inventory\Bridges\ExternalApprovalBridge($gateway));
    businessPost('purchase', 'P', 10, 40000);
    $gateway->requireApproval();
    $count = businessPost('stock_count', 'C', 7);
    expect($count->status->value)->toBe('waiting_approval')
        ->and((float) DB::table('inv_stock_cards')->value('running_qty'))->toBe(10.0);
    $count->status = \ESolution\Inventory\Enums\DocumentStatus::APPROVED;
    $count->save();
    app(InventoryManager::class)->resumeApproved($count->id);
    app(InventoryManager::class)->resumeApproved($count->id);
    expect((float) DB::table('inv_stock_cards')->value('running_qty'))->toBe(7.0)
        ->and(StockLedger::whereIn('document_line_id', $count->lines()->select('id'))->count())->toBe(1);
});

it('reports backdated transactions in posting order without rewriting historical costs', function (): void {
    businessPost('purchase', 'P1', 10, 40000);
    businessPost('sale', 'S1', 5, price: 60000);
    app(InventoryManager::class)->post(new DocumentData('purchase', 1, '2026-09-01', [new LineData(1, 1, 1, 5, unitCost: 50000)], externalId: 'BACKDATE'));
    $rows = app(InventoryManager::class)->stockCard(1, 1);
    expect($rows[1]['cogs'])->toBe(200000.0)->and($rows[2]['date'])->toBe('2026-09-01')
        ->and($rows[2]['balance_amount'])->toBe(450000.0);
});


it('posts mixed count gains and losses in a single mapped journal', function (): void {
    DB::table('inv_items')->insert(['id' => 3,'sku' => 'SECOND','name' => 'Second','item_type' => 'stock','item_category_id' => 1,'base_uom_id' => 1,'is_active' => true]);
    $gateway = new FakeAccountingJournalGateway();
    app()->instance(AccountingBridge::class, new ExternalAccountingBridge($gateway, app(ServiceCodeResolver::class), app(MappingKeyGuard::class)));
    businessPost('purchase', 'P1', 10, 40000);
    config(['inventory.accounting.service_code_map.stock_count' => 'STOCK_COUNT',
        'inventory.accounting.document_mapping_keys.stock_count' => [
            'inventory_debit' => 'stock_count_inventory_d','gain_credit' => 'stock_count_gain_k',
            'loss_debit' => 'stock_count_loss_d','inventory_credit' => 'stock_count_inventory_k',
        ],
    ]);
    $manager = app(InventoryManager::class);
    $manager->post(new DocumentData('stock_count', 1, '2026-10-01', [new LineData(1, 1, 1, 8),new LineData(3, 1, 1, 2, unitCost: 10000)], externalId: 'MIX'));
    expect($gateway->posts[1]['payload']['items'])->toBe([
        ['mapping_key' => 'stock_count_inventory_d','amount' => 20000.0],
        ['mapping_key' => 'stock_count_gain_k','amount' => 20000.0],
        ['mapping_key' => 'stock_count_loss_d','amount' => 80000.0],
        ['mapping_key' => 'stock_count_inventory_k','amount' => 80000.0],
    ]);
    $manager->post(new DocumentData('stock_count', 1, '2026-10-01', [new LineData(1, 1, 1, 8)], externalId: 'SAME'));
    expect($gateway->posts)->toHaveCount(2);
});

it('recognizes a supplier return loss and rejects a missing invoice price', function (): void {
    $gateway = new FakeAccountingJournalGateway();
    app()->instance(AccountingBridge::class, new ExternalAccountingBridge($gateway, app(ServiceCodeResolver::class), app(MappingKeyGuard::class)));
    config(['inventory.accounting.document_mapping_keys.purchase_return' => [
        'payable_debit' => 'purchase_return_ap_d','inventory_credit' => 'purchase_return_inventory_k',
        'gain_credit' => 'purchase_return_gain_k','loss_debit' => 'purchase_return_loss_d',
    ]]);
    businessPost('purchase', 'P1', 10, 40000);
    businessPost('purchase', 'P2', 10, 50000);
    expect(fn() => businessPost('purchase_return', 'NO-PRICE', 5))->toThrow(DomainException::class, 'transactionPrice');
    businessPost('purchase_return', 'R1', 5, price: 40000);
    expect($gateway->posts[2]['payload']['items'])->toContain(['mapping_key' => 'purchase_return_loss_d','amount' => 25000.0]);
});

it('rejects conflicting purchase cost and invalid discounts before recording stock', function (): void {
    expect(fn() => businessPost('purchase','P1',10,40000,price: 50000))->toThrow(DomainException::class,'must equal');
    expect(fn() => businessPost('purchase','P2',10,price: 100,discount: 101))->toThrow(DomainException::class,'cover the discount');
    expect(fn() => businessPost('purchase','P3',10,40000,discount: 1))->toThrow(DomainException::class,'requires a transaction price');
    expect(Document::count())->toBe(0)->and(StockLedger::count())->toBe(0);
});
