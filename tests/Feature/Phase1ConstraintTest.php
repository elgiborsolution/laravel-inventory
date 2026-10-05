<?php

use ESolution\Inventory\Models\StockCard;
use ESolution\Inventory\Models\StockLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->installInventorySchema();
});

it('enforces scoped Stock Card uniqueness', function (): void {
    StockCard::create(['item_id' => 1, 'scope_type' => 'warehouse', 'scope_id' => 1, 'as_of' => '2026-08-31']);

    expect(fn() => StockCard::create(['item_id' => 1, 'scope_type' => 'warehouse', 'scope_id' => 1, 'as_of' => '2026-08-31']))
        ->toThrow(QueryException::class);
});

it('enforces reservation fulfillment idempotency at database level', function (): void {
    $reservationId = DB::table('inv_reservations')->insertGetId([
        'item_id' => 1,
        'warehouse_id' => 1,
        'source_type' => 'sales_order',
        'source_id' => 'SO-CONSTRAINT',
        'reserved_qty' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $row = ['reservation_id' => $reservationId, 'idempotency_key' => 'KEY-1', 'qty' => 1, 'created_at' => now()];
    DB::table('inv_reservation_consumptions')->insert($row);

    expect(fn() => DB::table('inv_reservation_consumptions')->insert($row))
        ->toThrow(QueryException::class);
});

it('exposes no model path for updating or deleting posted ledger entries', function (): void {
    $this->postReceipt(externalId: 'LEDGER-IMMUTABLE');
    $ledger = StockLedger::query()->firstOrFail();

    expect(fn() => $ledger->forceFill(['qty' => 2])->save())->toThrow(LogicException::class)
        ->and(fn() => $ledger->delete())->toThrow(LogicException::class);
});

test('database rejects reservation totals where consumed plus released exceeds reserved')->todo('Portable reservation total constraint is not implemented.');
test('database guarantees batch and serial ownership matches document item')->todo('Cross-table ownership requires service validation or database trigger design.');
