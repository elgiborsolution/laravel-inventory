<?php

use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\DTO\TransferData;
use ESolution\Inventory\Models\Document;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Services\InventoryManager;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    if (! getenv('INVENTORY_TEST_DATABASE')) {
        $this->markTestSkipped('Requires isolated MySQL/MariaDB via INVENTORY_TEST_DATABASE.');
    }
    $this->installInventorySchema();
    DB::table('inv_organizations')->insert(['id' => 2, 'type' => 'warehouse', 'code' => 'BRANCH', 'name' => 'Branch']);
    $this->postReceipt(5, 10);
});

/** @return list<array<string, mixed>> */
function concurrentStockOperations(array $operations): array
{
    DB::beginTransaction();
    Item::query()->lockForUpdate()->findOrFail(1);
    $workers = [];
    $readyFiles = [];
    try {
        foreach ($operations as [$operation, $argument]) {
            $readyFile = tempnam(sys_get_temp_dir(), 'inventory-ready-');
            unlink($readyFile);
            $readyFiles[] = $readyFile;
            $worker = new Process(
                [PHP_BINARY, dirname(__DIR__) . '/Support/stock-operation-worker.php', $operation, (string) $argument],
                env: ['INVENTORY_WORKER_READY' => $readyFile],
            );
            $worker->setTimeout(30);
            $worker->start();
            $workers[] = $worker;
        }
        // Both independent processes must be ready while the stock lock is held.
        $deadline = microtime(true) + 20;
        foreach ($workers as $index => $worker) {
            while (! is_file($readyFiles[$index])) {
                if (! $worker->isRunning() || microtime(true) > $deadline) {
                    throw new RuntimeException('Stock worker did not become ready: ' . $worker->getErrorOutput() . $worker->getOutput());
                }
                usleep(10000);
                clearstatcache();
            }
        }
    } finally {
        DB::commit();
        foreach ($readyFiles as $readyFile) {
            if (is_file($readyFile)) {
                unlink($readyFile);
            }
        }
    }
    $results = [];
    foreach ($workers as $worker) {
        $worker->wait();
        if (! $worker->isSuccessful()) {
            throw new RuntimeException($worker->getErrorOutput() . $worker->getOutput());
        }
        $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    return $results;
}

it('serializes competing transfers without overselling', function (): void {
    $results = concurrentStockOperations([['transfer', 'RACE-A'], ['transfer', 'RACE-B']]);
    expect(count(array_filter($results, fn($result) => isset($result['id']))))->toBe(1)
        ->and(count(array_filter($results, fn($result) => isset($result['rejected']))))->toBe(1)
        ->and(app(InventoryManager::class)->availability(1, 1)->onHandQty)->toBe(2.0)
        ->and(app(InventoryManager::class)->availability(1, 2)->onHandQty)->toBe(3.0);
});

it('returns one transfer for simultaneous identical requests', function (): void {
    $results = concurrentStockOperations([['transfer', 'RACE-SAME'], ['transfer', 'RACE-SAME']]);
    expect($results[0]['id'])->toBe($results[1]['id'])
        ->and(Document::where('document_type', 'warehouse_transfer')->count())->toBe(1);
});

it('returns one reversal for simultaneous cancellation requests', function (): void {
    $transfer = app(InventoryManager::class)->transfer(new TransferData(1, 2, '2026-09-30', 'ORIGINAL', [new LineData(1, 1, 1, 3)]));
    $results = concurrentStockOperations([['reverse', $transfer->id], ['reverse', $transfer->id]]);
    expect($results[0]['id'])->toBe($results[1]['id'])
        ->and(Document::where('reversal_of_id', $transfer->id)->count())->toBe(1)
        ->and(app(InventoryManager::class)->availability(1, 1)->onHandQty)->toBe(5.0);
});
