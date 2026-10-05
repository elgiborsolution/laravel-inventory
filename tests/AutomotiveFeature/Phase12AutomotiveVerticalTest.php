<?php

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\Certificate;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\Serial;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\InventoryAutomotive\Services\AutomotivePreset;
use ESolution\InventoryAutomotive\Services\PartUsageReport;
use ESolution\InventoryAutomotive\Services\WorkOrderParts;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->installInventorySchema();
    app(AutomotivePreset::class)->apply(Item::findOrFail(1));
    Serial::create(['id' => 1, 'item_id' => 1, 'warehouse_id' => 1, 'serial_no' => 'AUTO-1', 'status' => 'in_stock']);
    Certificate::create(['trackable_type' => (new Serial())->getMorphClass(), 'trackable_id' => 1, 'type' => 'compliance', 'number' => 'CERT-1', 'issued_at' => '2026-01-01', 'expires_at' => '2027-01-01']);
    app(InventoryManager::class)->post(new DocumentData(
        'purchase_receipt',
        1,
        '2026-09-16',
        [new LineData(1, 1, 1, 1, unitCost: 50, serialId: 1)],
        externalId: 'AUTO-GR',
    ));
});
function autoIssue(string $key = 'AUTO-GI'): Document
{
    return app(WorkOrderParts::class)->issue($key, 1, '2026-09-16', 'external-work-order', 'WO-1', 'external-vehicle', 'VIN-1', [new LineData(1, 1, 1, 1, serialId: 1)]);
}
test('AC12-01 preset preserves tracking and unions serial certificate requirements', function (): void {
    $item = Item::findOrFail(1);
    $item->tracking = ['custom' => true, 'required_serial_certificates_on_issue' => ['safety']];
    $item->save();
    $item = app(AutomotivePreset::class)->apply($item);
    expect($item->tracking['custom'])->toBeTrue()
        ->and($item->tracking['required_serial_certificates_on_issue'])->toBe(['safety', 'compliance'])
        ->and($item->tracking['serial_required_on_receipt'])->toBeTrue()
        ->and($item->tracking['serial_required_on_issue'])->toBeTrue()
        ->and(fn() => app(AutomotivePreset::class)->apply(Item::findOrFail(2)))->toThrow(DomainException::class);
});
test('AC12-02 issue posts ordinary Core costs and retry does not consume twice', function (): void {
    $issue = autoIssue();
    expect(autoIssue()->id)->toBe($issue->id)
        ->and($issue->status->value)->toBe('posted')
        ->and(app(InventoryManager::class)->availability(1, 1)->onHandQty)->toBe(0.0)
        ->and((float) StockLedger::where('direction', 'out')->sum('amount'))->toBe(50.0)
        ->and(StockLedger::where('direction', 'out')->count())->toBe(1);
});
test('serial and valid Compliance are enforced for work orders and ordinary issues', function (): void {
    Certificate::query()->delete();
    expect(fn() => autoIssue())->toThrow(DomainException::class, 'valid compliance');
    Certificate::create(['trackable_type' => (new Serial())->getMorphClass(), 'trackable_id' => 1, 'type' => 'compliance', 'number' => 'EXPIRED', 'expires_at' => '2026-09-15']);
    expect(fn() => autoIssue())->toThrow(DomainException::class, 'valid compliance');
    expect(fn() => app(InventoryManager::class)->post(new DocumentData('goods_issue', 1, '2026-09-16', [new LineData(1, 1, 1, 1)])))->toThrow(DomainException::class, 'requires a serial');
    expect(StockLedger::where('direction', 'out')->count())->toBe(0);
});
test('AC12-04 external polymorphic Work Order and vehicle need no local models', function (): void {
    $issue = autoIssue();
    expect($issue->source_type)->toBe('external-work-order')
        ->and($issue->source_id)->toBe('WO-1')
        ->and($issue->party_type)->toBe('external-vehicle')
        ->and($issue->party_id)->toBe('VIN-1');
});
test('AC12-05 report traces posted ledger quantity cost item and serial with scoped filters', function (): void {
    $issue = autoIssue();
    $report = app(PartUsageReport::class);
    $row = $report->query(1, 'external-work-order', 'WO-1', 'external-vehicle', 'VIN-1', 1, 1)->first();
    expect($row->document_id)->toBe($issue->id)
        ->and((float) $row->qty)->toBe(1.0)
        ->and((float) $row->amount)->toBe(50.0)
        ->and($row->serial_id)->toBe(1)
        ->and($report->query(2)->get())->toHaveCount(0)
        ->and($report->query(1, vehicleType: 'different-type', vehicleId: 'VIN-1')->get())->toHaveCount(0)
        ->and($report->query(1, serialId: 99)->get())->toHaveCount(0);
    $query = $report->query(1, 'external-work-order', 'WO-1');
    $plan = DB::select('EXPLAIN QUERY PLAN ' . $query->toSql(), $query->getBindings());
    expect($plan)->not->toBeEmpty();
    // Readable evidence for the index decision; no production index is inferred from SQLite.
    fwrite(STDOUT, "\nAutomotive report plan: " . json_encode($plan) . "\n");
});
test('AC12-06 direct Core posting fails closed with either accounting switch', function (): void {
    foreach (['inventory.accounting.enabled', 'inventory-automotive.accounting.enabled'] as $key) {
        config()->set($key, true);
        expect(fn() => app(InventoryManager::class)->post(new DocumentData(
            'work_order_parts_issue',
            1,
            '2026-09-16',
            [new LineData(1, 1, 1, 1, serialId: 1)],
            externalId: 'DIRECT',
            sourceType: 'wo',
            sourceId: '1',
            partyType: 'vehicle',
            partyId: '1',
        )))->toThrow(DomainException::class, 'fail-closed');
        config()->set($key, false);
    }
    expect(StockLedger::where('direction', 'out')->count())->toBe(0)
        ->and(Document::where('external_id', 'DIRECT')->count())->toBe(0);
});
test('accounting guard is applied again on approval resume', function (): void {
    app()->instance(\ESolution\Inventory\Contracts\ApprovalBridge::class, new \ESolution\Inventory\Tests\Fakes\SpyApprovalBridge(true));
    app()->forgetInstance(InventoryManager::class);
    app()->forgetInstance(\ESolution\Inventory\Services\PostingEngine::class);
    $doc = autoIssue();
    expect($doc->status->value)->toBe('waiting_approval');
    DB::table('inv_documents')->where('id', $doc->id)->update(['status' => 'approved']);
    config()->set('inventory-automotive.accounting.enabled', true);
    expect(fn() => app(\ESolution\Inventory\Services\PostingEngine::class)->resumeApproved($doc->id))->toThrow(DomainException::class, 'fail-closed');
    expect(StockLedger::where('direction', 'out')->count())->toBe(0);
});
test('AC12-03 AC12-07 Automotive has only Core dependency and delegates stock implementation', function (): void {
    $root = dirname(__DIR__, 2) . '/packages/automotive';
    $manifest = json_decode(file_get_contents($root . '/composer.json'), true);
    expect(array_keys($manifest['require']))->toBe(['php', 'elgibor-solution/laravel-inventory']);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')) as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        foreach (['InventoryAsset\\', 'InventoryFood\\', 'InventoryHealthcare\\', 'InventoryManufacturing\\', 'InventoryProject\\', 'InventoryRetail\\', 'InventoryWms\\', 'StockLedger::create', 'CostLayer::create', 'implements MovementPolicy', 'implements CostingDriver'] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    }
    expect(autoIssue()->status->value)->toBe('posted');
});
