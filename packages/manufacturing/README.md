# Laravel Inventory Manufacturing

## Kegunaan dan fitur

**Kegunaan:** Produksi atau perakitan barang berdasarkan BOM (daftar kebutuhan bahan).

**Fungsi dan fitur:** BOM berversi yang tidak dapat diubah setelah aktivasi; Production Order; konsumsi bahan dan penerimaan hasil dalam satu transaksi; biaya aktual bahan; scrap, selisih pemakaian/hasil, dan produksi bertahap melalui WIP.

**Contoh penggunaan:** Merakit produk dari beberapa komponen, lalu menghitung biaya hasil berdasarkan biaya komponen yang benar-benar dikeluarkan.

**Batas dan integrasi host:** Referensi pesanan bisnis berasal dari host. Penyelesaian produksi saat ini mensyaratkan accounting Core/modul nonaktif dan NullAccountingBridge.

Lihat [perbandingan sembilan modul](../../docs/INSTALLATION.md#51-kegunaan-fungsi-dan-fitur-sembilan-modul)
untuk memilih modul yang sesuai.

## Activation

Bundled installation: install Core once, then run `php artisan config:clear`,
`php artisan vendor:publish --tag=inventory-manufacturing-config`, and
`php artisan migrate`. Check `php artisan inventory:modules`. No separate module
download is needed. Core registers the module provider on the next boot when
its host config file exists; do not register a module provider manually for this
activation flow. See [installation and upgrade](../../docs/INSTALLATION.md) for
config cache, worker restarts, upgrades, and deactivation.

## Technical behavior

`elgibor-solution/laravel-inventory-manufacturing` is a bundled module that depends only on Inventory Core. It owns all `invm_*`
tables and does not implement stock posting or costing logic.

## BOM and production

BOM versions are editable only while draft. Activation makes the version and
its components immutable; a Production Order always retains the exact version
it used. Both components and outputs must be active Core Items with an allowed
stock-bearing Item Type.

`ProductionOrderService::complete()` runs in one database transaction. It:

1. posts ordinary `production_consumption` through Core;
2. reads the actual outbound Stock Ledger cost;
3. posts ordinary `production_receipt` at the rolled unit cost;
4. records immutable scrap/usage and yield variances; and
5. marks the Production Order completed with both Core document links.

MTS, MTO, BTO, and ATO are source-link modes, not Movement Policies. MTO/BTO/ATO
require string-safe business source references. Multi-stage WIP uses a normal
stock Item as one order's output and the child order's BOM component, linked by
`parent_order_id`.

## Accounting blocker

Manufacturing accounting is intentionally fail-closed. Completion is allowed
only while Core resolves `NullAccountingBridge`, Core accounting is disabled,
and `inventory-manufacturing.accounting.enabled` is false. No Manufacturing
service codes are guessed. Enabling either setting before verified service
codes are published rejects the operation before any stock effect.


## Contoh service

Ikuti [konvensi payload, hasil, dan error](../../docs/SALES_PURCHASING_INTEGRATION.md#konvensi-contoh-dan-error).
JSON model di bawah merupakan proyeksi field terpilih, bukan seluruh serialisasi
Eloquent. ID ilustratif harus diganti dengan master host yang valid.

Prasyarat: barang output ID 2 dan bahan ID 1 aktif dengan tipe diizinkan, UOM 1,
serta bahan tersedia di gudang 1. Accounting Core/modul harus nonaktif dengan
NullAccountingBridge. Contoh memakai bahan tanpa tracking dan tidak memakai
approval agar penyelesaian menghasilkan kedua posting dalam transaksi yang sama.

```php
use ESolution\InventoryManufacturing\Services\BomService;
use ESolution\InventoryManufacturing\Services\ProductionOrderService;
use ESolution\InventoryManufacturing\DTO\BomComponentData;
use ESolution\InventoryManufacturing\DTO\ProductionOrderData;

$definitions = app(BomService::class);
$definition = $definitions->create('BOM-001', 'Produk contoh', 2);
$version = $definitions->createVersion(
    $definition->id, 1, [new BomComponentData(itemId: 1, uomId: 1, qty: 2)],
);
$version = $definitions->activate($version->id);
$service = app(ProductionOrderService::class);
$order = $service->create(new ProductionOrderData(
    orderNo: 'PROD-001', bomVersionId: $version->id,
    organizationId: 1, warehouseId: 1, plannedQty: 3,
));
$order = $service->complete(
    $order->id, actualOutputQty: 3,
    actualComponentQtyByItem: [1 => 6], trxDate: '2026-10-08',
);
$result = ['id' => $order->id, 'status' => $order->status,
    'actual_output_qty' => (float) $order->actual_output_qty,
    'actual_component_cost' => (float) $order->actual_component_cost,
    'output_unit_cost' => (float) $order->output_unit_cost];
```

Jika biaya bahan 5000/unit, proyeksi hasil complete adalah:

```json
{"id":1,"status":"completed","actual_output_qty":3,"actual_component_cost":30000,"output_unit_cost":10000}
```

| Method | Input / default | Hasil, efek, dan retry |
|---|---|---|
| `BomService::create` | code/name:string nonkosong, outputItemId:int | Model BOM; membuat master, tidak idempotent berdasarkan request key. Kode duplikat dapat ditolak constraint. |
| `createVersion` | ID master:int, outputQty:float >0, components:list<BomComponentData> tidak kosong; effectiveFrom/effectiveTo:?string=null | Model versi draft dengan components; setiap panggilan membuat versi baru. Component DTO: itemId/uomId:int, qty:float >0, sequence:int=0. |
| `activate` | versionId:int | Versi status active, data kemudian immutable. Pemanggilan ulang versi tersebut mengembalikan versi yang sudah aktif/published. |
| `assertVersionItems` | Model versi | void; memeriksa output dan bahan masih aktif/diizinkan. Contoh `$definitions->assertVersionItems($version)`. |
| `ProductionOrderService::create` | DTO ProductionOrderData di atas | Model order status planned; belum ada perubahan stok. Nomor yang sudah ada memeriksa field identitas. |
| `complete` | ID order:int, actualOutputQty:float >0; actualComponentQtyByItem=[], componentLocationByItem=[], outputLocationId=null, trxDate=null (hari ini) | Model completed dengan cost dan referensi dokumen; konsumsi bahan + penerimaan hasil atomik. Retry completed mengembalikan hasil lama, tidak mengoreksi qty dengan payload baru. |

`actualComponentQtyByItem` berbentuk map itemId=>qty; key yang tidak ada di versi
ditolak. Tanpa override, kebutuhan mengikuti plannedQty/outputQty versi. Kuantitas
bahan boleh nol untuk melewati bahan, tetapi minimal satu bahan harus dikonsumsi.
`componentLocationByItem` berbentuk itemId=>storageLocationId; siapkan lokasi jika
scope rack. Seluruh perubahan rollback bila salah satu posting gagal.

Contoh error: versi belum active, output/komponen tidak aktif, bahan
menduplikasi item atau mengonsumsi outputnya sendiri, stok kurang, accounting aktif.
`complete()` bukan endpoint edit produksi; buat alur koreksi host jika perlu.

ProductionOrderData wajib: orderNo:string, bomVersionId/organizationId/warehouseId:int,
plannedQty:float. Opsional: sourceMode='mts', sourceType/sourceId=null,
parentOrderId=null, meta=[]. Mode mts/mto/bto/ato; mode selain mts memerlukan
sourceType/sourceId. parentOrderId menghubungkan WIP; output parent harus menjadi
komponen child dan parent selesai sebelum child diselesaikan. Completion juga
mencatat variances. `ManufacturingAccountingGuard::assertDisabled()` -> void,
melempar DomainException bila accounting tidak memenuhi syarat; dipakai internal.
