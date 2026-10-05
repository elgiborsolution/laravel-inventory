<?php

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\Batch;
use ESolution\Inventory\Models\Certificate;
use ESolution\Inventory\Models\CostLayer;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\InventoryHealthcare\Services\HealthcarePreset;
use ESolution\InventoryHealthcare\Services\RecallService;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->installInventorySchema();
    $item = Item::query()->create([
        'id' => 3,
        'sku' => 'HEALTHCARE-1',
        'name' => 'Healthcare Stock',
        'item_type' => 'stock',
        'item_category_id' => 1,
        'base_uom_id' => 1,
        'is_active' => true,
        'tracking' => ['preserved_setting' => true],
    ]);
    app(HealthcarePreset::class)->apply($item);
});

function healthcareBatch(string $number, ?string $expiry, int $itemId = 3): Batch
{
    return Batch::query()->create([
        'item_id' => $itemId,
        'batch_no' => $number,
        'expires_at' => $expiry,
        'status' => 'available',
    ]);
}

function healthcareCoa(Batch $batch, string $number, ?string $expiry = '2027-12-31'): Certificate
{
    return Certificate::query()->create([
        'trackable_type' => $batch->getMorphClass(),
        'trackable_id' => $batch->getKey(),
        'type' => 'coa',
        'number' => $number,
        'expires_at' => $expiry,
    ]);
}

function healthcareReceipt(Batch $batch, float $qty, string $externalId, string $trxDate, array $meta = []): Document
{
    return app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: $trxDate,
        externalId: $externalId,
        lines: [new LineData(3, 1, 1, $qty, unitCost: 5, batchId: $batch->id, meta: $meta)],
    ));
}

function healthcareIssue(float $qty, string $externalId, ?int $batchId = null): Document
{
    return app(InventoryManager::class)->post(new DocumentData(
        type: 'goods_issue',
        organizationId: 1,
        trxDate: '2026-09-02',
        externalId: $externalId,
        lines: [new LineData(3, 1, 1, $qty, batchId: $batchId)],
    ));
}

function issuedBatchIds(Document $document): array
{
    return StockLedger::query()
        ->whereIn('document_line_id', $document->lines->pluck('id'))
        ->orderBy('id')
        ->get()
        ->map(fn(StockLedger $ledger): int => (int) CostLayer::query()->findOrFail($ledger->cost_layer_id)->batch_id)
        ->all();
}

test('AC8-01 Healthcare preset merges FEFO batch expiry COA and disposition rules', function (): void {
    $item = Item::query()->findOrFail(3);

    expect($item->costing_method)->toBe('fefo')
        ->and($item->tracking['preserved_setting'])->toBeTrue()
        ->and($item->tracking['batch_required_on_receipt'])->toBeTrue()
        ->and($item->tracking['expiry_required_on_receipt'])->toBeTrue()
        ->and($item->tracking['required_batch_certificates_on_issue'])->toBe(['coa'])
        ->and($item->tracking['expired_receipt_dispositions'])->toBe(['quarantine', 'disposal']);
});

test('AC8-02 FEFO consumes earliest valid expiry regardless of receipt order', function (): void {
    $laterExpiry = healthcareBatch('AC8-02-LATER', '2027-12-31');
    $earlierExpiry = healthcareBatch('AC8-02-EARLIER', '2026-12-31');
    healthcareCoa($laterExpiry, 'COA-LATER');
    healthcareCoa($earlierExpiry, 'COA-EARLIER');
    healthcareReceipt($laterExpiry, 3, 'AC8-02-RECEIVED-FIRST', '2026-08-01');
    healthcareReceipt($earlierExpiry, 3, 'AC8-02-RECEIVED-SECOND', '2026-09-01');

    $issue = healthcareIssue(4, 'AC8-02-ISSUE');

    expect(issuedBatchIds($issue))->toBe([$earlierExpiry->id, $laterExpiry->id])
        ->and((float) CostLayer::query()->where('batch_id', $earlierExpiry->id)->value('remaining_qty'))->toBe(0.0)
        ->and((float) CostLayer::query()->where('batch_id', $laterExpiry->id)->value('remaining_qty'))->toBe(2.0);
});

test('FEFO breaks equal expiry and receipt timestamps by Cost Layer ID', function (): void {
    $first = healthcareBatch('FEFO-TIE-FIRST', '2027-01-01');
    $second = healthcareBatch('FEFO-TIE-SECOND', '2027-01-01');
    healthcareCoa($first, 'COA-TIE-FIRST');
    healthcareCoa($second, 'COA-TIE-SECOND');
    healthcareReceipt($first, 1, 'FEFO-TIE-FIRST-GR', '2026-09-01');
    healthcareReceipt($second, 1, 'FEFO-TIE-SECOND-GR', '2026-09-01');

    $issue = healthcareIssue(1, 'FEFO-TIE-GI');

    expect(issuedBatchIds($issue))->toBe([$first->id]);
});

test('AC8-03 Core FEFO works while WMS provider and tables are absent', function (): void {
    $batch = healthcareBatch('AC8-03', '2027-01-01');
    healthcareCoa($batch, 'COA-AC8-03');
    healthcareReceipt($batch, 2, 'AC8-03-RECEIPT', '2026-09-01');

    $issue = healthcareIssue(1, 'AC8-03-ISSUE');

    expect(issuedBatchIds($issue))->toBe([$batch->id])
        ->and(Schema::hasTable('invw_tasks'))->toBeFalse();
});

test('AC8-04 expired Goods Issue is blocked for explicit and automatic selection', function (): void {
    $expired = healthcareBatch('AC8-04-EXPIRED', '2026-08-31');
    healthcareCoa($expired, 'COA-EXPIRED');
    healthcareReceipt($expired, 2, 'AC8-04-CONTROLLED', '2026-09-02', ['disposition' => 'quarantine']);

    expect(fn() => healthcareIssue(1, 'AC8-04-EXPLICIT', $expired->id))
        ->toThrow(DomainException::class, 'expired batch')
        ->and(fn() => healthcareIssue(1, 'AC8-04-AUTO'))
        ->toThrow(DomainException::class, 'Insufficient stock');
});

test('AC8-05 only controlled expired receipt is accepted and remains traceable', function (): void {
    $expired = healthcareBatch('AC8-05-EXPIRED', '2026-08-31');

    expect(fn() => healthcareReceipt($expired, 1, 'AC8-05-UNCONTROLLED', '2026-09-02'))
        ->toThrow(DomainException::class, 'controlled disposition');

    $document = healthcareReceipt(
        $expired,
        1,
        'AC8-05-DISPOSAL',
        '2026-09-02',
        ['disposition' => 'disposal', 'case_reference' => 'DISPOSAL-100'],
    );
    $line = $document->lines->firstOrFail();

    expect((int) $line->batch_id)->toBe($expired->id)
        ->and($line->meta['disposition'])->toBe('disposal')
        ->and($line->meta['case_reference'])->toBe('DISPOSAL-100')
        ->and(StockLedger::query()->where('document_line_id', $line->id)->count())->toBe(1);
});

test('AC8-06 recall blocks only its batch and release restores eligibility', function (): void {
    $recalled = healthcareBatch('AC8-06-RECALLED', '2027-01-01');
    $available = healthcareBatch('AC8-06-AVAILABLE', '2027-02-01');
    healthcareCoa($recalled, 'COA-RECALLED');
    healthcareCoa($available, 'COA-AVAILABLE');
    healthcareReceipt($recalled, 2, 'AC8-06-RECALLED-GR', '2026-09-01');
    healthcareReceipt($available, 2, 'AC8-06-AVAILABLE-GR', '2026-09-01');
    $recalls = app(RecallService::class);
    $recall = $recalls->recall('RECALL-AC8-06', $recalled->id, 'Quality deviation');

    expect(fn() => healthcareIssue(1, 'AC8-06-BLOCKED', $recalled->id))
        ->toThrow(DomainException::class, 'not available');
    $otherIssue = healthcareIssue(1, 'AC8-06-OTHER', $available->id);
    expect(issuedBatchIds($otherIssue))->toBe([$available->id])
        ->and($recalled->refresh()->status)->toBe('recalled');

    $recalls->release($recall->id);
    $releasedIssue = healthcareIssue(1, 'AC8-06-RELEASED', $recalled->id);
    expect(issuedBatchIds($releasedIssue))->toBe([$recalled->id])
        ->and($recalled->refresh()->status)->toBe('available');
});

test('AC8-07 recall forward trace identifies outbound documents for the exact batch', function (): void {
    $batch = healthcareBatch('AC8-07-TRACE', '2027-01-01');
    healthcareCoa($batch, 'COA-TRACE');
    healthcareReceipt($batch, 3, 'AC8-07-GR', '2026-09-01');
    $outbound = healthcareIssue(1, 'AC8-07-GI', $batch->id);
    $recall = app(RecallService::class)->recall('RECALL-AC8-07', $batch->id, 'Trace test');

    $trace = app(RecallService::class)->forwardTrace($recall->id);

    expect($trace->pluck('id')->all())->toBe([$outbound->id])
        ->and($trace->first()->external_id)->toBe('AC8-07-GI');
});

test('AC8-08 COA policy excludes invalid batches and accepts a valid certificate', function (): void {
    $withoutCoa = healthcareBatch('AC8-08-NO-COA', '2026-11-01');
    $withCoa = healthcareBatch('AC8-08-WITH-COA', '2027-01-01');
    healthcareReceipt($withoutCoa, 2, 'AC8-08-NO-COA-GR', '2026-09-01');
    healthcareReceipt($withCoa, 2, 'AC8-08-WITH-COA-GR', '2026-09-01');
    healthcareCoa($withoutCoa, 'COA-EXPIRED-AC8-08', '2026-08-31');
    healthcareCoa($withCoa, 'COA-VALID-AC8-08');

    expect(fn() => healthcareIssue(1, 'AC8-08-EXPLICIT-BLOCK', $withoutCoa->id))
        ->toThrow(DomainException::class, 'valid coa');
    $automatic = healthcareIssue(1, 'AC8-08-AUTO');
    expect(issuedBatchIds($automatic))->toBe([$withCoa->id]);
});

test('AC8-09 Healthcare depends only on Core and has no WMS or sibling dependency', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode(
        file_get_contents($root . '/packages/healthcare/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $dependencies = array_keys($composer['require']);
    $source = '';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/packages/healthcare/src'));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source .= file_get_contents($file->getPathname());
        }
    }

    expect($dependencies)->toContain('elgibor-solution/laravel-inventory')
        ->and($source)->not->toContain('InventoryWms\\')
        ->and($source)->not->toContain('InventoryRetail\\')
        ->and($source)->not->toContain('InventoryManufacturing\\');
    foreach ($dependencies as $dependency) {
        if ($dependency !== 'elgibor-solution/laravel-inventory') {
            expect($dependency)->not->toStartWith('elgibor-solution/laravel-inventory-');
        }
    }
});

test('Healthcare receipt rejects missing batch and null expiry', function (): void {
    expect(fn() => app(InventoryManager::class)->post(new DocumentData(
        type: 'purchase_receipt',
        organizationId: 1,
        trxDate: '2026-09-02',
        externalId: 'AC8-MISSING-BATCH',
        lines: [new LineData(3, 1, 1, 1, unitCost: 5)],
    )))->toThrow(DomainException::class, 'requires a batch');

    $noExpiry = healthcareBatch('AC8-NO-EXPIRY', null);
    expect(fn() => healthcareReceipt($noExpiry, 1, 'AC8-NULL-EXPIRY', '2026-09-02'))
        ->toThrow(DomainException::class, 'requires batch expiry');
});
