<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\DTO\ReversalRequest;
use ESolution\Inventory\DTO\TransferData;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\Inventory\Tests\TestCase;

if (! getenv('INVENTORY_TEST_DATABASE')) {
    throw new RuntimeException('Worker requires an isolated real test database.');
}

final class StockOperationWorker extends TestCase
{
    public function bootWorker(): void
    {
        parent::setUp();
    }

    public function placeholder(): void {}
}

$worker = new StockOperationWorker('placeholder');
$cacheFiles = [];
foreach (['APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE'] as $cacheVariable) {
    $path = 'bootstrap/cache/inventory-worker-' . getmypid() . '-' . $cacheVariable . '.php';
    putenv($cacheVariable . '=' . $path);
    $_ENV[$cacheVariable] = $path;
    $cacheFiles[] = dirname(__DIR__, 2) . '/vendor/orchestra/testbench-core/laravel/' . $path;
}
register_shutdown_function(function () use ($cacheFiles): void {
    foreach ($cacheFiles as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});
$worker->bootWorker();
$manager = app(InventoryManager::class);
if ($readyFile = getenv('INVENTORY_WORKER_READY')) {
    touch($readyFile);
}
try {
    $document = $argv[1] === 'reverse'
        ? $manager->reverse(new ReversalRequest((int) $argv[2], 'Concurrent cancellation'))
        : $manager->transfer(new TransferData(1, 2, '2026-09-30', $argv[2], [new LineData(1, 1, 1, 3)]));
    echo json_encode(['id' => $document->id], JSON_THROW_ON_ERROR);
} catch (DomainException $exception) {
    echo json_encode(['rejected' => $exception->getMessage()], JSON_THROW_ON_ERROR);
}
