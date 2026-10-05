# Warehouse transfer and stock reversal

`Inventory::transfer(TransferData $data)` moves standard stock between warehouses
in one transaction. Each FIFO source layer produces an outbound ledger entry and
an inbound entry with the same quantity and unit cost. The destination receives
separate cost layers; costs are not averaged across transferred layers.

```php
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\DTO\TransferData;
use ESolution\Inventory\DTO\ReversalRequest;
use ESolution\Inventory\Facades\Inventory;

$transfer = Inventory::transfer(new TransferData(
    organizationId: $organizationId,
    targetWarehouseId: $branchWarehouseId,
    trxDate: now()->toDateString(),
    externalId: "tent-schedule:{$scheduleId}:v1",
    lines: [new LineData($itemId, $uomId, $hoWarehouseId, 3)],
    sourceType: 'tent_schedule',
    sourceId: (string) $scheduleId,
));

$reversal = Inventory::reverse(new ReversalRequest(
    documentId: $transfer->id,
    reason: 'Schedule cancelled',
    externalId: "tent-schedule:{$scheduleId}:cancel-v1",
));
```

## Supported scope

- Active standard stock items, base UOM, no serial or batch tracking.
- FIFO costing at warehouse scope; source and target warehouses must differ.
- Accounting and Approval bridges disabled. Host authorization and approval stay
  with the application. Unsupported bridges, movement policies, costing methods,
  tracking, locations and cost overrides are rejected before committing effects.
- Reversal supports posted `purchase_receipt`, `positive_adjustment`,
  `goods_issue`, `negative_adjustment`, `scrap`, and `warehouse_transfer` documents.
  Reservation-linked fulfillment, custom line metadata, negative/adjusted cost
  layers, and reversing a reversal are not supported.
- Cross-company accounting is outside this API; the host must map and authorize
  warehouse relationships appropriate to its organization.

## Corrections and retries

A transfer requires an external ID. An identical retry returns the original
document; a changed payload using that ID is rejected. Reversal defaults to
`reversal:{documentId}` when no external ID is provided. An identical reversal
retry returns the existing reversal; a different request cannot reverse it again.
A unique database index on `reversal_of_id` reinforces this rule.

Reversal appends opposite ledger entries, restores the original cost layers, and
marks the original document reversed. It does not edit/delete original ledger
entries. Reversal uses the current date; original ledger costs, including blended
bonus costs, are retained.

An inbound layer already consumed cannot be removed, even when replacement stock
exists in another layer. Reverse dependent issues/transfers first. This stricter
provenance check prevents removing historical value from unrelated replacement
stock. Negative-stock configuration never permits transfer/reversal to overdraw.
Reservations and active stock locks reduce availability for outbound effects.

Wrap reversal, replacement transfer/receipt, and business record updates in the
same host `DB::transaction()` on the same connection. A failed replacement then
rolls back the reversal as well. Do not repost documents already represented by
legacy opening balances; legacy migration needs explicit mapping.

Stock operations lock item rows in ID order, sharing the lock anchor used by
reservations and ordinary posting. Posted events are dispatched after commit.
Direct SQL stock writes bypass these protections and are not supported.

## Verification

```bash
php vendor/bin/pest tests/Feature/TransferReversalTest.php
```

For real database tests, create a disposable database whose name starts with
`inventory_test_`, then set `INVENTORY_TEST_DATABASE`. Optional variables are
`INVENTORY_TEST_HOST`, `INVENTORY_TEST_PORT`, `INVENTORY_TEST_USER`,
`INVENTORY_TEST_PASSWORD`, and `INVENTORY_TEST_PREFIX` (e.g. `es_`).

```bash
php vendor/bin/pest tests/Feature/TransferReversalTest.php tests/Feature/TransferConcurrencyTest.php
```

**The selected test database is reset with `migrate:fresh` for each scenario.**
Never select an application database. Concurrency tests launch independent PHP
processes to verify competing transfers, identical retries and double reversal.
Without the dedicated database configuration, concurrency tests are skipped.
