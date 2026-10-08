# Laravel Inventory Project

## Kegunaan dan fitur

**Kegunaan:** Penyediaan dan pengambilan material untuk proyek atau site.

**Fungsi dan fitur:** Alokasi berbasis reservasi, penambahan alokasi, pemindahan alokasi secara atomik, pengambilan material sebagian, serta laporan alokasi dan konsumsi.

**Contoh penggunaan:** Mereservasi material untuk proyek konstruksi lalu mengeluarkannya bertahap sesuai kebutuhan lapangan.

**Batas dan integrasi host:** Identitas proyek berasal dari host. Pemindahan alokasi reservasi bukan perpindahan fisik barang; perubahan stok tetap melalui Core. Modul tidak menyediakan manajemen jadwal atau anggaran proyek.

Lihat [perbandingan sembilan modul](../../docs/INSTALLATION.md#51-kegunaan-fungsi-dan-fitur-sembilan-modul)
untuk memilih modul yang sesuai.

## Activation

Bundled installation: install Core once, then run `php artisan config:clear`,
`php artisan vendor:publish --tag=inventory-project-config`, and
`php artisan migrate`. Check `php artisan inventory:modules`. No separate module
download is needed. Core registers the module provider on the next boot when
its host config file exists; do not register a module provider manually for this
activation flow. See [installation and upgrade](../../docs/INSTALLATION.md) for
config cache, worker restarts, upgrades, and deactivation.

## Technical behavior

`elgibor-solution/laravel-inventory-project` is a bundled module that
depends only on Inventory Core and owns all `invp_*` tables.

Each ProjectAllocation stores a polymorphic project reference, Core Site and
warehouse organization references, one stock Item, and exactly one Core
Reservation. Site is an existing Core organization ancestor (or the warehouse
itself); this package does not introduce an organization level.

Replenishment creates a distinct allocation and Reservation. Reallocation is an
atomic release from the source Reservation plus a new destination allocation,
with the explicit pair stored in `invp_project_reallocations`. A failed
destination rolls the source release back.

Partial material draw posts an ordinary Core Goods Issue and links its exact
line quantity through `ReservationConsumptionData`. Reporting sums persisted
Reservation quantities and consumption links rather than inferring allocation
from unrelated Stock Ledger movements.

Project publishes a minimal activation config and no sector preset and registers no Document Type,
MovementPolicy, or CostingDriver. Accounting and approval remain governed by
Core; allocation itself works when both optional bridges are absent.


## Contoh service

Ikuti [konvensi payload, hasil, dan error](../../docs/SALES_PURCHASING_INTEGRATION.md#konvensi-contoh-dan-error).
Contoh JSON model adalah proyeksi field terpilih; semua ID/nilai ilustratif dan
memerlukan data master yang sesuai. Contoh method terpisah bukan instruksi untuk
menjalankan seluruh mutasi berulang pada data produksi.

Prasyarat: item stock aktif dan stok tersedia. siteId harus sama dengan gudang atau
ancestor organisasi gudang, keduanya aktif. Project eksternal dirujuk oleh type/ID.
AllocationData wajib: allocationNo/projectType/projectId:string, siteId/warehouseId/
itemId:int, qty:float >0. Opsional: kind='initial' (initial/replenishment/reallocation),
sourceAllocationId=null, meta=[]. Untuk tambahan/pemindahan gunakan method khusus.

```php
use ESolution\InventoryProject\DTO\AllocationData;
use ESolution\InventoryProject\Services\ProjectAllocationService;
use ESolution\InventoryProject\Services\ProjectAllocationReport;

$service = app(ProjectAllocationService::class);
$allocation = $service->allocate(new AllocationData(
    allocationNo: 'ALLOC-001', projectType: 'project', projectId: 'PRJ-001',
    siteId: 1, warehouseId: 1, itemId: 1, qty: 10,
));
$document = $service->draw($allocation->id, 3, 'DRAW-001', '2026-10-08');
$totals = app(ProjectAllocationReport::class)->totals('project', 'PRJ-001');
$result = get_object_vars($totals);
```

Hasil DTO total setelah allocate 10 dan draw 3 (tanpa approval tertunda):

```json
{"allocatedQty":10,"consumedQty":3,"releasedQty":0,"remainingQty":7,"allocationCount":1}
```

| Method | Contoh input / default opsional | Hasil dan dampak |
|---|---|---|
| `allocate` | AllocationData di atas | ProjectAllocation status active, reservation_id; reservasi tanpa mutasi stok. |
| `replenish` | `$service->replenish($allocation->id, 'ALLOC-002', 5)`; meta=[] | ProjectAllocation baru kind replenishment; reservasi tambahan, bukan penerimaan fisik. |
| `reallocate` | `$service->reallocate('MOVE-001', $allocation->id, 'ALLOC-003', 2, 2, 2)`; meta=[] | ProjectReallocation dengan source_allocation_id, destination_allocation_id, qty=2; melepas sumber dan membuat reservasi tujuan atomik. Site/gudang tujuan harus valid dan tersedia. |
| `draw` | allocationId:int, qty:float, externalId:string; trxDate=null (hari ini), storageLocationId/batchId/serialId=null | Document goods_issue; konsumsi reservasi dan ledger atomik saat posted. |
| `ProjectAllocationReport::totals` | projectType/projectId wajib; siteId/itemId=null | AllocationTotals seperti JSON; nol bila tidak ada alokasi. |

Qty melebihi saldo alokasi, stok tujuan tidak tersedia, atau gudang bukan turunan
site ditolak. Nomor allocate/reallocate mendeteksi konflik identitas; metadata bukan
seluruhnya pembanding retry. Retry draw harus memakai externalId dan tanggal/payload
yang sama; simpan tanggal eksplisit agar retry besok tidak berbeda. Total allocated
adalah penjumlahan histori reservasi, termasuk hasil reallocation; gunakan
remainingQty untuk sisa aktif, bukan menganggap allocatedQty sebagai pembelian neto.
