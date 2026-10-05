<?php

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\DTO\ReversalRequest;
use ESolution\Inventory\DTO\TransferData;
use ESolution\Inventory\Enums\DocumentStatus;
use ESolution\Inventory\Models\CostLayer;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->installInventorySchema();
    DB::table('inv_organizations')->insert([
        'id' => 2, 'type' => 'warehouse', 'code' => 'BRANCH', 'name' => 'Branch',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

function tentTransfer(float $qty = 3, string $key = 'TENT-1'): TransferData
{
    return new TransferData(1, 2, '2026-09-30', $key, [new LineData(1, 1, 1, $qty)], 'tent_schedule', '10');
}

it('transfers FIFO slices without changing total inventory value and retries once', function (): void {
    $this->postReceipt(2, 10);
    $this->postReceipt(4, 20, externalId: 'SECOND');
    $manager = app(InventoryManager::class);
    $transfer = $manager->transfer(tentTransfer());
    expect($manager->transfer(tentTransfer())->id)->toBe($transfer->id)
        ->and($manager->availability(1, 1)->onHandQty)->toBe(3.0)
        ->and($manager->availability(1, 2)->onHandQty)->toBe(3.0)
        ->and((float) DB::table('inv_stock_cards')->where('scope_id', 1)->value('running_value'))->toBe(60.0)
        ->and((float) DB::table('inv_stock_cards')->where('scope_id', 2)->value('running_value'))->toBe(40.0)
        ->and(CostLayer::where('scope_id', 2)->orderBy('id')->pluck('unit_cost')->map(fn($v) => (float) $v)->all())->toBe([10.0, 20.0])
        ->and(StockLedger::whereIn('document_line_id', $transfer->lines()->select('id'))->count())->toBe(4);
    expect(fn() => $manager->transfer(tentTransfer(4)))->toThrow(DomainException::class);
});

it('reverses a transfer exactly and prevents a second reversal', function (): void {
    $this->postReceipt(2, 10);
    $this->postReceipt(4, 20, externalId: 'SECOND');
    $manager = app(InventoryManager::class);
    $transfer = $manager->transfer(tentTransfer());
    $originalLedger = StockLedger::orderBy('id')->get()->toArray();
    $request = new ReversalRequest($transfer->id, 'Cancel schedule');
    $reversal = $manager->reverse($request);
    expect($manager->reverse($request)->id)->toBe($reversal->id)
        ->and($transfer->fresh()->status)->toBe(DocumentStatus::REVERSED)
        ->and($manager->availability(1, 1)->onHandQty)->toBe(6.0)
        ->and($manager->availability(1, 2)->onHandQty)->toBe(0.0)
        ->and((float) DB::table('inv_stock_cards')->where('scope_id', 1)->value('running_value'))->toBe(100.0)
        ->and((float) DB::table('inv_stock_cards')->where('scope_id', 2)->value('running_value'))->toBe(0.0)
        ->and(StockLedger::orderBy('id')->limit(count($originalLedger))->get()->toArray())->toBe($originalLedger);
    expect(fn() => $manager->reverse(new ReversalRequest($transfer->id, 'Other', 'another-key')))->toThrow(DomainException::class);
    expect(fn() => $manager->reverse(new ReversalRequest($reversal->id, 'Reverse reversal')))->toThrow(DomainException::class);
});

it('blocks cancellation after damage and allows it after damage reversal', function (): void {
    $this->postReceipt(8, 10);
    $manager = app(InventoryManager::class);
    $transfer = $manager->transfer(tentTransfer());
    $damage = $manager->post(new DocumentData('scrap', 1, '2026-09-30', [new LineData(1, 1, 2, 1)], 'DAMAGE-1'));
    $count = Document::count();
    expect(fn() => $manager->reverse(new ReversalRequest($transfer->id, 'Cancel')))->toThrow(DomainException::class)
        ->and(Document::count())->toBe($count)
        ->and($transfer->fresh()->status)->toBe(DocumentStatus::POSTED)
        ->and($manager->availability(1, 2)->onHandQty)->toBe(2.0);
    $manager->reverse(new ReversalRequest($damage->id, 'Correct damage'));
    $manager->reverse(new ReversalRequest($transfer->id, 'Cancel'));
    expect($manager->availability(1, 1)->onHandQty)->toBe(8.0);
});

it('reverses purchase receipts including blended bonus cost', function (): void {
    $receipt = $this->postReceipt(4, 15, 2);
    $manager = app(InventoryManager::class);
    $manager->reverse(new ReversalRequest($receipt->id, 'Wrong receipt'));
    expect($manager->availability(1, 1)->onHandQty)->toBe(0.0)
        ->and((float) CostLayer::sum('remaining_qty'))->toBe(0.0)
        ->and((float) DB::table('inv_stock_cards')->value('running_value'))->toBe(0.0);
});

it('rolls back all transfer legs when a later line lacks stock', function (): void {
    $this->postReceipt(5, 10);
    $manager = app(InventoryManager::class);
    $input = new TransferData(1, 2, '2026-09-30', 'MULTI', [new LineData(1, 1, 1, 3), new LineData(1, 1, 1, 3)]);
    expect(fn() => $manager->transfer($input))->toThrow(DomainException::class)
        ->and(Document::count())->toBe(1)
        ->and(StockLedger::count())->toBe(1)
        ->and($manager->availability(1, 1)->onHandQty)->toBe(5.0)
        ->and($manager->availability(1, 2)->onHandQty)->toBe(0.0);
});

it('rolls back a schedule correction if its replacement fails', function (): void {
    $this->postReceipt(5, 10);
    $manager = app(InventoryManager::class);
    $transfer = $manager->transfer(tentTransfer());
    expect(fn() => DB::transaction(function () use ($manager, $transfer): void {
        $manager->reverse(new ReversalRequest($transfer->id, 'Edit'));
        $manager->transfer(tentTransfer(6, 'REVISION-2'));
    }))->toThrow(DomainException::class)
        ->and($transfer->fresh()->status)->toBe(DocumentStatus::POSTED)
        ->and(Document::whereNotNull('reversal_of_id')->count())->toBe(0)
        ->and($manager->availability(1, 2)->onHandQty)->toBe(3.0);
});

it('protects reservations when transferring and reversing receipts', function (): void {
    $receipt = $this->postReceipt(5, 10);
    $manager = app(InventoryManager::class);
    $manager->reserve(1, 4, 1, 'sales_order', '1');
    expect(fn() => $manager->transfer(tentTransfer()))->toThrow(DomainException::class);
    expect(fn() => $manager->reverse(new ReversalRequest($receipt->id, 'Cancel')))->toThrow(DomainException::class);
    expect($manager->availability(1, 1)->onHandQty)->toBe(5.0);
});

it('fails closed for unsupported costing and bridges', function (): void {
    $this->postReceipt();
    config(['inventory.accounting.enabled' => true]);
    expect(fn() => app(InventoryManager::class)->transfer(tentTransfer()))->toThrow(DomainException::class);
    config(['inventory.accounting.enabled' => false, 'inventory.costing.default_method' => 'moving_average']);
    expect(fn() => app(InventoryManager::class)->transfer(tentTransfer()))->toThrow(DomainException::class);
    expect(Document::count())->toBe(1);
});

it('rejects invalid warehouses quantities tracking and UOM', function (string $case): void {
    $this->postReceipt();
    $input = match ($case) {
        'same warehouse' => new TransferData(1, 1, '2026-09-30', 'BAD', [new LineData(1, 1, 1, 1)]),
        'negative' => tentTransfer(-1),
        'precision' => tentTransfer(0.0000001),
        'uom' => new TransferData(1, 2, '2026-09-30', 'BAD', [new LineData(1, 2, 1, 1)]),
        'tracking' => new TransferData(1, 2, '2026-09-30', 'BAD', [new LineData(1, 1, 1, 1, serialId: 1)]),
    };
    expect(fn() => app(InventoryManager::class)->transfer($input))->toThrow(DomainException::class)
        ->and(Document::count())->toBe(1);
})->with(['same warehouse', 'negative', 'precision', 'uom', 'tracking']);
