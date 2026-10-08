# Laravel Inventory WMS

## Kegunaan dan fitur

**Kegunaan:** Gudang yang membutuhkan pengaturan pekerjaan penyimpanan dan pengambilan barang.

**Fungsi dan fitur:** Strategi put-away (penempatan), picking FIFO/FEFO, task, wave (kelompok tugas picking), LPN (identitas container), cross-docking, dan pekerjaan replenishment (pengisian ulang lokasi).

**Contoh penggunaan:** Barang diterima, petugas memperoleh tugas penempatan, kemudian mengambil barang berdasarkan wave untuk pengiriman.

**Batas dan integrasi host:** Saran lokasi dan pekerjaan replenishment tidak otomatis mengubah saldo. Host menghubungkan penyelesaian pekerjaan dengan posting/transfer Core; integrasi transportasi juga milik host.

Lihat [perbandingan sembilan modul](../../docs/INSTALLATION.md#51-kegunaan-fungsi-dan-fitur-sembilan-modul)
untuk memilih modul yang sesuai.

## Activation

Bundled installation: install Core once, then run `php artisan config:clear`,
`php artisan vendor:publish --tag=inventory-wms-config`, and
`php artisan migrate`. Check `php artisan inventory:modules`. No separate module
download is needed. Core registers the module provider on the next boot when
its host config file exists; do not register a module provider manually for this
activation flow. See [installation and upgrade](../../docs/INSTALLATION.md) for
config cache, worker restarts, upgrades, and deactivation.

## Technical behavior

`elgibor-solution/laravel-inventory-wms` is an optional bundled module for physical warehouse orchestration. It depends only on
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
