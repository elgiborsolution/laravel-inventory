# Laravel Inventory WMS

`elgibor-solution/laravel-inventory-wms` is an optional, independently
installable vertical for physical warehouse orchestration. It depends only on
Inventory Core and owns every `invw_*` table.

## Strategy ownership

`PutAwayStrategy` and `PickingStrategy` are WMS contracts. Core owns the stock
truth, posting, costing, and ledger; WMS consumes that truth to produce
operational suggestions. Installing or removing WMS therefore does not alter
Core's public posting API.

Available put-away strategies are `fixed`, `dynamic`, `random`, `dedicated`,
`nearest`, and `empty_bin`. Random placement is seeded by the request key so a
retry returns the same suggestion. Picking supports deterministic FIFO and
FEFO allocations; suggestions never post or mutate inventory.

## Workflow and physical units

After a Core document commits, the WMS listener creates idempotent put-away,
cross-dock, or pick tasks. Waves group open pick tasks without owning document
status. LPN operations atomically maintain a container's location and content;
they do not duplicate Core ledger movements.

The replenishment scheduler creates internal work only:

```bash
php artisan inventory-wms:replenish --warehouse=1
```

Completing that work should invoke the host application's ordinary Core
transfer posting. The scheduler itself never posts stock.

## TMS integration pattern

TMS remains an application-level integration, not a Composer dependency. A
host may listen for a released/completed WMS wave, transform its tasks into a
shipment payload, and store the TMS shipment identity in its own integration
table. Inbound TMS callbacks should reference the wave code and use their event
ID as an idempotency key. TMS must not write `inv_*`/`invw_*` tables or mark Core
documents posted; inventory changes continue through Core posting and WMS only
tracks physical work.
