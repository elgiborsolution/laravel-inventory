<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class LibraryRaceWorker extends \ESolution\Inventory\Tests\LibraryTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.testing.database', getenv('LIBRARY_RACE_DB'));
        $app['config']->set('database.connections.testing.busy_timeout', 5000);
    }
    public function runWorker(): void
    {
        $this->setUp();
        try {
            \Illuminate\Support\Carbon::setTestNow(getenv('LIBRARY_RACE_TIME'));
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            touch(getenv('LIBRARY_RACE_GATE') . '.' . getenv('LIBRARY_RACE_KEY') . '.ready');
            $deadline = microtime(true) + 30;
            while (! file_exists(getenv('LIBRARY_RACE_GATE'))) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Barrier timeout');
                }
                usleep(10000);
                clearstatcache();
            }
            $service = app(\ESolution\InventoryLibrary\Services\CirculationService::class);
            $operation = getenv('LIBRARY_RACE_OP');
            if ($operation === 'checkout') {
                $service->checkout(getenv('LIBRARY_RACE_KEY'), 1, 'patron', getenv('LIBRARY_RACE_KEY'), '2026-10-01');
            } elseif ($operation === 'checkin') {
                $service->checkin(1);
            } else {
                $service->expireReadyHolds();
            }
            echo "SUCCESS\n";
        } catch (\DomainException $e) {
            echo 'REJECTED:' . $e->getMessage() . "\n";
        } catch (\Illuminate\Database\QueryException $e) {
            // SQLite serializes writers and may reject an overlapping snapshot.
            if (! str_contains($e->getMessage(), 'locked')) {
                throw $e;
            }
            echo "BUSY\n";
        } finally {
            // This standalone process has no PHPUnit runner configuration.
            // Disconnect explicitly; process exit disposes the application.
            \Illuminate\Support\Facades\DB::disconnect();
        }
    }
}
(new LibraryRaceWorker('runWorker'))->runWorker();
