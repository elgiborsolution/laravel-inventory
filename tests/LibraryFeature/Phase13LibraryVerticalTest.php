<?php

use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Models\Item;
use ESolution\Inventory\Models\Reservation;
use ESolution\Inventory\Models\Serial;
use ESolution\Inventory\Models\StockLedger;
use ESolution\Inventory\Services\InventoryManager;
use ESolution\InventoryLibrary\Models\Circulation;
use ESolution\InventoryLibrary\Models\Hold;
use ESolution\InventoryLibrary\Services\CirculationService;
use ESolution\InventoryLibrary\Services\LibraryPreset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->installInventorySchema();
    Carbon::setTestNow('2026-09-18 10:00:00');
    $item = Item::findOrFail(1);
    $item->tracking = ['preserved' => true];
    $item->save();
    app(LibraryPreset::class)->apply($item);
    Serial::create(['id' => 1, 'item_id' => 1, 'warehouse_id' => 1, 'serial_no' => 'COPY-1', 'status' => 'in_stock']);
    app(InventoryManager::class)->post(new DocumentData(
        'purchase_receipt',
        1,
        '2026-09-18',
        [new LineData(1, 1, 1, 1, unitCost: 50, serialId: 1)],
        externalId: 'LIBRARY-GR',
    ));
});
afterEach(function (): void {
    Carbon::setTestNow();
});

function libraryLoan(string $no = 'LOAN-1', string $patron = 'P1'): Circulation
{
    return app(CirculationService::class)->checkout($no, 1, 'patron', $patron, '2026-10-01');
}

test('AC13-01 preset preserves settings and requires per-copy serials', function (): void {
    $tracking = Item::findOrFail(1)->tracking;
    expect($tracking['preserved'])->toBeTrue()->and($tracking['serial_required_on_receipt'])->toBeTrue()
        ->and($tracking['serial_required_on_issue'])->toBeTrue();
    expect(fn() => app(InventoryManager::class)->post(new DocumentData(
        'purchase_receipt',
        1,
        '2026-09-18',
        [new LineData(1, 1, 1, 1, unitCost: 1)],
    )))->toThrow(DomainException::class, 'serial');
    expect(fn() => app(LibraryPreset::class)->apply(Item::findOrFail(2)))->toThrow(DomainException::class);
});
test('AC13-02 AC13-03 checkout and checkin change only reservations and retry safely', function (): void {
    $before = StockLedger::count();
    $loan = libraryLoan();
    expect(libraryLoan()->id)->toBe($loan->id)->and(Reservation::count())->toBe(1)
        ->and((float) Reservation::findOrFail($loan->reservation_id)->reserved_qty)->toBe(1.0)
        ->and(app(InventoryManager::class)->availability(1, 1)->availableQty)->toBe(0.0);
    $service = app(CirculationService::class);
    $service->checkin($loan->id);
    $service->checkin($loan->id);
    expect(Reservation::findOrFail($loan->reservation_id)->status)->toBe('released')
        ->and(app(InventoryManager::class)->availability(1, 1)->onHandQty)->toBe(1.0)
        ->and(app(InventoryManager::class)->availability(1, 1)->availableQty)->toBe(1.0)
        ->and(StockLedger::count())->toBe($before);
});
test('AC13-04 active copy has one database allocation and rejects another checkout', function (): void {
    $loan = libraryLoan();
    expect(fn() => libraryLoan('LOAN-2', 'P2'))->toThrow(DomainException::class, 'already on loan');
    expect(fn() => DB::table('invl_active_allocations')->insert(['serial_id' => 1,
        'reservation_id' => $loan->reservation_id, 'circulation_id' => $loan->id]))->toThrow(\Illuminate\Database\QueryException::class);
    expect(Circulation::count())->toBe(1)->and(Reservation::count())->toBe(1);
});
test('AC13-05 AC13-06 Holds wait without reservation then become ready in timestamp and ID order', function (): void {
    $s = app(CirculationService::class);
    $first = $s->hold('H1', 1, 1, 'patron', 'P1');
    $second = $s->hold('H2', 1, 1, 'patron', 'P2');
    expect(Reservation::count())->toBe(0)->and(app(InventoryManager::class)->availability(1, 1)->availableQty)->toBe(1.0);
    expect($s->fulfillNextHold(1)->id)->toBe($first->id)->and($s->fulfillNextHold(1)->id)->toBe($first->id)
        ->and($second->refresh()->status)->toBe('waiting')->and(Reservation::count())->toBe(1);
    expect(fn() => libraryLoan('WRONG', 'P2'))->toThrow(DomainException::class, 'another patron');
    $reservationId = DB::table('invl_active_allocations')->value('reservation_id');
    $loan = libraryLoan();
    expect((int) $loan->reservation_id)->toBe((int) $reservationId)->and(Reservation::count())->toBe(1)
        ->and($first->refresh()->status)->toBe('fulfilled');
    $s->checkin($loan->id);
    expect($second->refresh()->status)->toBe('ready')->and(Reservation::count())->toBe(2);
});
test('AC13-07 expiry skips expired waiting holds and advances once', function (): void {
    $s = app(CirculationService::class);
    $h1 = $s->hold('H1', 1, 1, 'patron', 'P1');
    $skip = $s->hold('SKIP', 1, 1, 'patron', 'PX', '2026-09-19');
    $h2 = $s->hold('H2', 1, 1, 'patron', 'P2');
    $s->fulfillNextHold(1);
    Carbon::setTestNow('2026-09-20 10:00:00');
    $s->expireReadyHolds();
    $s->expireReadyHolds();
    expect($h1->refresh()->status)->toBe('expired')->and($skip->refresh()->status)->toBe('expired')
        ->and($h2->refresh()->status)->toBe('ready')->and(Reservation::count())->toBe(2)
        ->and(Reservation::where('status', 'active')->count())->toBe(1);
});
test('AC13-09 renewal audit overdue and fine are outside stock ledger', function (): void {
    $s = app(CirculationService::class);
    $loan = libraryLoan();
    $ledger = StockLedger::count();
    $s->renew($loan->id, 'REN-1', '2026-10-03');
    $s->renew($loan->id, 'REN-1', '2026-10-03');
    expect(DB::table('invl_renewals')->count())->toBe(1)->and($loan->refresh()->is_overdue)->toBeFalse();
    $s->hold('H1', 1, 1, 'patron', 'P2');
    expect(fn() => $s->renew($loan->id, 'REN-2', '2026-10-04'))->toThrow(DomainException::class, 'Hold queue');
    Carbon::setTestNow('2026-10-04');
    expect($loan->refresh()->is_overdue)->toBeTrue();
    $fine = $s->recordFine($loan->id, 'FINE-1', 100, 'IDR', 'Late return');
    expect($s->recordFine($loan->id, 'FINE-1', 100, 'IDR', 'Late return'))->toBe($fine)
        ->and(DB::table('invl_fines')->count())->toBe(1)->and(StockLedger::count())->toBe($ledger);
    $s->checkin($loan->id);
    expect($loan->refresh()->is_overdue)->toBeFalse();
});
test('AC13-10 independent Library uses only Core with disabled bridges', function (): void {
    $root = dirname(__DIR__, 2) . '/packages/library';
    $composer = json_decode(file_get_contents($root . '/composer.json'), true);
    expect(array_keys($composer['require']))->toBe(['php', 'elgibor-solution/laravel-inventory']);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')) as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        foreach (['InventoryAsset\\', 'InventoryFood\\', 'InventoryHealthcare\\', 'InventoryManufacturing\\',
            'InventoryProject\\', 'InventoryRetail\\', 'InventoryWms\\', 'InventoryAutomotive\\',
            'StockLedger::create', 'CostLayer::create', 'DocumentTypeDefinition'] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    }
    expect(app(\ESolution\Inventory\Contracts\AccountingBridge::class))->toBeInstanceOf(\ESolution\Inventory\Bridges\NullAccountingBridge::class)
        ->and(app(\ESolution\Inventory\Contracts\ApprovalBridge::class))->toBeInstanceOf(\ESolution\Inventory\Bridges\NullApprovalBridge::class);
    libraryLoan();
});

function libraryRace(array $operations, string $time): array
{
    $database = tempnam(sys_get_temp_dir(), 'library-race-db-');
    $gate = tempnam(sys_get_temp_dir(), 'library-race-gate-');
    unlink($gate);
    DB::statement('VACUUM INTO ?', [$database]);
    $workers = [];
    try {
        foreach ($operations as $index => $operation) {
            $process = new \Symfony\Component\Process\Process(
                [PHP_BINARY, dirname(__DIR__) . '/Support/library-race-worker.php'],
                dirname(__DIR__, 2),
                ['LIBRARY_RACE_DB' => $database, 'LIBRARY_RACE_GATE' => $gate, 'LIBRARY_RACE_TIME' => $time,
                    'LIBRARY_RACE_OP' => $operation, 'LIBRARY_RACE_KEY' => 'RACE-' . $index],
            );
            $process->setTimeout(60);
            $process->start();
            $workers[] = $process;
        }
        $deadline = microtime(true) + 20;
        foreach ($workers as $index => $worker) {
            while (! file_exists($gate . '.RACE-' . $index . '.ready')) {
                if (! $worker->isRunning() || microtime(true) > $deadline) {
                    throw new RuntimeException('Worker readiness timeout: ' . $worker->getOutput() . $worker->getErrorOutput());
                }
                usleep(10000);
                clearstatcache();
            }
        }
        touch($gate);
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getOutput() . $worker->getErrorOutput());
        }
        $pdo = new PDO('sqlite:' . $database);
        $counts = [];
        foreach (['invl_circulations', 'invl_active_allocations', 'inv_reservations', 'inv_stock_ledgers'] as $table) {
            $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }
        $counts['active_reservations'] = (int) $pdo->query("SELECT COUNT(*) FROM inv_reservations WHERE status = 'active'")->fetchColumn();
        $counts['ready_holds'] = (int) $pdo->query("SELECT COUNT(*) FROM invl_holds WHERE status = 'ready'")->fetchColumn();
        $counts['expired_holds'] = (int) $pdo->query("SELECT COUNT(*) FROM invl_holds WHERE status = 'expired'")->fetchColumn();
        $counts['outputs'] = array_map(fn($p) => $p->getOutput(), $workers);
        $pdo = null;
        return $counts;
    } finally {
        foreach ($workers as $index => $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
            $readyPath = $gate . '.RACE-' . $index . '.ready';
            if (file_exists($readyPath)) {
                unlink($readyPath);
            }
        }
        if (file_exists($gate)) {
            unlink($gate);
        }
        if (file_exists($database)) {
            unlink($database);
        }
    }
}

test('AC13-04 two independent checkout workers cannot allocate the same copy twice', function (): void {
    $result = libraryRace(['checkout', 'checkout'], '2026-09-18 10:00:00');
    expect($result['invl_circulations'])->toBe(1)
        ->and($result['invl_active_allocations'])->toBe(1)
        ->and($result['inv_reservations'])->toBe(1)
        ->and($result['inv_stock_ledgers'])->toBe(1);
});
test('AC13-08 concurrent checkin retry and ready expiry fulfill the next Hold once', function (): void {
    $s = app(CirculationService::class);
    $loan = libraryLoan();
    $s->hold('H1', 1, 1, 'patron', 'P1');
    $s->hold('H2', 1, 1, 'patron', 'P2');
    $s->checkin($loan->id);
    $result = libraryRace(['checkin', 'expiry'], '2026-09-20 10:00:00');
    expect($result['invl_active_allocations'])->toBe(1)
        ->and($result['active_reservations'])->toBe(1)
        ->and($result['ready_holds'])->toBe(1)
        ->and($result['expired_holds'])->toBe(1)
        ->and($result['inv_reservations'])->toBe(3)
        ->and($result['inv_stock_ledgers'])->toBe(1);
});
