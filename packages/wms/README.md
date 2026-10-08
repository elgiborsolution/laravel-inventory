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


## Contoh service

Ikuti [konvensi payload, hasil, dan error](../../docs/SALES_PURCHASING_INTEGRATION.md#konvensi-contoh-dan-error).
JSON model di bawah merupakan proyeksi field terpilih, bukan seluruh serialisasi
Eloquent. ID ilustratif harus diganti dengan master host yang valid.

Prasyarat: gudang dan StorageLocation aktif, LocationProfile dan aturan lokasi
sudah tersedia. Picking memerlukan ledger pada lokasi dengan picking_enabled.
Saran adalah hasil pembacaan, bukan reservasi atau perintah posting stok.

```php
use ESolution\InventoryWms\DTO\PutAwayRequest;
use ESolution\InventoryWms\DTO\PickingRequest;
use ESolution\InventoryWms\Services\PutAwayManager;
use ESolution\InventoryWms\Services\PickingManager;
use ESolution\InventoryWms\Services\LpnService;
use ESolution\InventoryWms\Services\WaveService;
use ESolution\InventoryWms\Services\ReplenishmentScheduler;

$location = app(PutAwayManager::class)->suggest(new PutAwayRequest(
    itemId: 1, warehouseId: 1, qty: 10, deterministicKey: 'GR-001-L1',
), 'dynamic');
$putAwayResult = ['id' => $location->id, 'code' => $location->code];
$suggestions = app(PickingManager::class)->suggest(new PickingRequest(1, 1, 5), 'fifo');
$pickingResult = array_map(fn($s) => get_object_vars($s), $suggestions);
```

Proyeksi lokasi terpilih dan contoh DTO picking pada dua lokasi:

```json
{"id":10,"code":"RACK-A"}
```

```json
[{"locationId":10,"batchId":null,"qty":3},{"locationId":11,"batchId":null,"qty":2}]
```

| Operasi | Parameter / contoh | Hasil / efek |
|---|---|---|
| `PutAwayManager::suggest` | PutAwayRequest; strategy:?string=null (rule/config) | StorageLocation. Request wajib itemId/warehouseId:int, qty:float >0; fromLocationId=null, zone=null, deterministicKey=''. |
| `PickingManager::suggest` | PickingRequest(itemId:int, warehouseId:int, qty:float >0), strategy=null (config) | list<PickingSuggestion> berisi locationId:int, batchId:?int, qty:float. |
| `LpnService::create` | `$lpn = app(LpnService::class)->create('LPN-001', 1, 10)`; locationId=null | Lpn dengan code, warehouse_id, storage_location_id; tidak menerima stok. |
| `LpnService::add` | `app(LpnService::class)->add($lpn->id, 1, 5)`; batchId=null | LpnContent qty=5 untuk konten baru; menambah isi container, bukan ledger. |
| `LpnService::remove` | `app(LpnService::class)->remove($lpn->id, 1, 2)`; batchId=null | LpnContent qty=3; null bila seluruh isi dihapus. |
| `LpnService::relocate` | `app(LpnService::class)->relocate($lpn->id, 11)` | Lpn dengan storage_location_id=11; lokasi harus aktif dalam gudang yang sama. |
| `WaveService::create` | `app(WaveService::class)->create('WAVE-001', 1, [20,21])` | Wave dengan relasi tasks; ID harus task pick open pada satu gudang. |
| `ReplenishmentScheduler::schedule` | `app(ReplenishmentScheduler::class)->schedule(1)`; warehouseId=null (semua) | list<Task>, `[]` bila tidak perlu. Contoh proyeksi `[{"type":"replenishment","qty":5}]`; membuat/mengambil pekerjaan yang pending, tanpa transfer stok. |

Jangan jalankan contoh task ID tanpa membuat task yang valid. Sumber task otomatis
adalah listener `TaskOrchestrator::handle(DocumentPosted $event): void`.

| Service pendukung / kontrak | Contoh input | Hasil |
|---|---|---|
| `LocationInventory::quantity` | `quantity(10, 1)`; itemId=null untuk semua item | float, misalnya 8; saldo fisik lokasi dari ledger. |
| `LocationCandidates::forPutAway` | PutAwayRequest di atas | Collection<LocationProfile> yang memenuhi syarat; bisa kosong. |
| `occupiedQty` | LocationProfile model | float total qty di lokasi, misalnya 8. |
| `itemQty` | LocationProfile model, itemId=1 | float qty item di lokasi, misalnya 3. |
| `PickAllocator::allocate` | PickingRequest di atas, method='fifo' atau 'fefo' | list<PickingSuggestion>; lebih baik panggil manager untuk validasi pilihan strategi. |
| `PutAwayStrategy::suggest` | PutAwayRequest | StorageLocation, implementasi fixed/dynamic/random/dedicated/nearest/empty_bin. |
| `PickingStrategy::suggest` | PickingRequest | list<PickingSuggestion>, implementasi FIFO/FEFO. |
| `TaskOrchestrator::handle` | Event DocumentPosted dari Core | void; task put-away/pick/cross-dock, dideduplikasi oleh kunci event/line. |

Nama service pendukung memakai namespace `ESolution\InventoryWms\Services`;
kontrak memakai `Contracts`. Method query tidak mengubah data. Saran ulang dapat
berubah jika stok berubah; random memakai deterministicKey untuk stabilitas.
Strategi tak dikenal atau stok pickable kurang melempar DomainException, misalnya
`Insufficient pickable stock for the requested quantity.`

**Retry:** create LPN berkode sama memeriksa gudang/lokasi; add/remove TIDAK memiliki
request key sehingga pengulangan mengubah qty lagi. Host perlu deduplikasi request.
Wave berkode sama menambahkan task tanpa melepas task lama, bukan mengganti set.
Scheduler memakai task pending/idempotency key, tetapi host tetap menjadwalkan
lock agar worker tidak tumpang tindih. Penyelesaian task/LPN bukan posting stok:
host harus menghubungkannya ke API Core yang sesuai dan masih didukung.
