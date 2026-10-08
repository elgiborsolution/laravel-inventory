# Laravel Inventory Food

## Kegunaan dan fitur

**Kegunaan:** Produksi makanan berdasarkan resep untuk stok atau pesanan.

**Fungsi dan fitur:** Recipe berversi, RecipeBatch dengan konsumsi bahan/penerimaan hasil atomik, biaya aktual bahan, produksi MTS (untuk stok) dan MTO (sesuai pesanan), serta preset batch dan persyaratan sertifikat halal.

**Contoh penggunaan:** Memproduksi satu batch makanan dari resep dan membukukan biaya bahan ke hasil produksi.

**Batas dan integrasi host:** FEFO dapat diatur melalui Core tanpa Healthcare/WMS. Penyelesaian RecipeBatch saat ini memerlukan accounting Core/Food nonaktif; integrasi pesanan dan UI tetap milik host.

Lihat [perbandingan sembilan modul](../../docs/INSTALLATION.md#51-kegunaan-fungsi-dan-fitur-sembilan-modul)
untuk memilih modul yang sesuai.

## Activation

Bundled installation: install Core once, then run `php artisan config:clear`,
`php artisan vendor:publish --tag=inventory-food-config`, and
`php artisan migrate`. Check `php artisan inventory:modules`. No separate module
download is needed. Core registers the module provider on the next boot when
its host config file exists; do not register a module provider manually for this
activation flow. See [installation and upgrade](../../docs/INSTALLATION.md) for
config cache, worker restarts, upgrades, and deactivation.

## Technical behavior

`elgibor-solution/laravel-inventory-food` is a bundled module that
depends only on Inventory Core and owns all `invf_*` tables.

Published Recipe versions and their components are immutable. A `RecipeBatch`
pairs ordinary Core `recipe_consumption` and `recipe_receipt` documents in one
transaction and rolls the actual consumed component cost into the output.

The configurable `food_order` Core transition hook creates one MTO RecipeBatch
per source line carrying `meta.recipe_version_id`. The source document and line
unique key makes repeated delivery idempotent. MTS batches are created directly
through `RecipeBatchService`.

`FoodPreset` merges mandatory receipt batch tracking and the `halal` certificate
requirement with existing Item tracking rules. Food intentionally does not
depend on Healthcare or WMS. Projects that need FEFO may configure the Core Item
with `costing_method = fefo` and expiry tracking; Healthcare is not required.

Food accounting remains fail-closed while verified accounting service codes are
unavailable. Both Core and Food accounting must remain disabled for RecipeBatch
completion.


## Contoh service

Ikuti [konvensi payload, hasil, dan error](../../docs/SALES_PURCHASING_INTEGRATION.md#konvensi-contoh-dan-error).
JSON model di bawah merupakan proyeksi field terpilih, bukan seluruh serialisasi
Eloquent. ID ilustratif harus diganti dengan master host yang valid.

Prasyarat: barang output ID 2 dan bahan ID 1 aktif dengan tipe diizinkan, UOM 1,
serta bahan tersedia di gudang 1. Accounting Core/modul harus nonaktif dengan
NullAccountingBridge. Contoh memakai bahan tanpa tracking dan tidak memakai
approval agar penyelesaian menghasilkan kedua posting dalam transaksi yang sama.

```php
use ESolution\InventoryFood\Services\RecipeService;
use ESolution\InventoryFood\Services\RecipeBatchService;
use ESolution\InventoryFood\DTO\RecipeComponentData;
use ESolution\InventoryFood\DTO\RecipeBatchData;

$definitions = app(RecipeService::class);
$definition = $definitions->create('RECIPE-001', 'Produk contoh', 2);
$version = $definitions->createVersion(
    $definition->id, 1, [new RecipeComponentData(itemId: 1, uomId: 1, qty: 2)],
);
$version = $definitions->publish($version->id);
$service = app(RecipeBatchService::class);
$batch = $service->create(new RecipeBatchData(
    batchNo: 'PROD-001', recipeVersionId: $version->id,
    organizationId: 1, warehouseId: 1, plannedQty: 3,
));
$batch = $service->complete(
    $batch->id, actualOutputQty: 3,
    actualComponentQtyByItem: [1 => 6], trxDate: '2026-10-08',
);
$result = ['id' => $batch->id, 'status' => $batch->status,
    'actual_output_qty' => (float) $batch->actual_output_qty,
    'actual_component_cost' => (float) $batch->actual_component_cost,
    'output_unit_cost' => (float) $batch->output_unit_cost];
```

Jika biaya bahan 5000/unit, proyeksi hasil complete adalah:

```json
{"id":1,"status":"completed","actual_output_qty":3,"actual_component_cost":30000,"output_unit_cost":10000}
```

| Method | Input / default | Hasil, efek, dan retry |
|---|---|---|
| `RecipeService::create` | code/name:string nonkosong, outputItemId:int | Model Recipe; membuat master, tidak idempotent berdasarkan request key. Kode duplikat dapat ditolak constraint. |
| `createVersion` | ID master:int, outputQty:float >0, components:list<RecipeComponentData> tidak kosong; effectiveFrom/effectiveTo:?string=null | Model versi draft dengan components; setiap panggilan membuat versi baru. Component DTO: itemId/uomId:int, qty:float >0, sequence:int=0. |
| `publish` | versionId:int | Versi status published, data kemudian immutable. Pemanggilan ulang versi tersebut mengembalikan versi yang sudah aktif/published. |
| `assertVersionItems` | Model versi | void; memeriksa output dan bahan masih aktif/diizinkan. Contoh `$definitions->assertVersionItems($version)`. |
| `RecipeBatchService::create` | DTO RecipeBatchData di atas | Model batch status planned; belum ada perubahan stok. Nomor yang sudah ada memeriksa field identitas. |
| `complete` | ID batch:int, actualOutputQty:float >0; actualComponentQtyByItem=[], componentLocationByItem=[], outputLocationId=null, trxDate=null (hari ini) | Model completed dengan cost dan referensi dokumen; konsumsi bahan + penerimaan hasil atomik. Retry completed mengembalikan hasil lama, tidak mengoreksi qty dengan payload baru. |

`actualComponentQtyByItem` berbentuk map itemId=>qty; key yang tidak ada di versi
ditolak. Tanpa override, kebutuhan mengikuti plannedQty/outputQty versi. Kuantitas
bahan boleh nol untuk melewati bahan, tetapi minimal satu bahan harus dikonsumsi.
`componentLocationByItem` berbentuk itemId=>storageLocationId; siapkan lokasi jika
scope rack. Seluruh perubahan rollback bila salah satu posting gagal.

Contoh error: versi belum published, output/komponen tidak aktif, bahan
menduplikasi item atau mengonsumsi outputnya sendiri, stok kurang, accounting aktif.
`complete()` bukan endpoint edit produksi; buat alur koreksi host jika perlu.

RecipeBatchData wajib: batchNo:string, recipeVersionId/organizationId/warehouseId:int,
plannedQty:float. Opsional: mode='mts', sourceDocumentId/sourceLineId/outputBatchId=null,
meta=[]. Mode mto membutuhkan sourceDocumentId dan sourceLineId Core. Bila output
memerlukan batch, supply outputBatchId. `complete()` juga menerima
`componentBatchByItem=[]` (itemId=>batchId) sebelum outputLocationId; gunakan named
arguments agar tidak keliru dengan signature Manufacturing.

| Service tambahan | Input contoh | Hasil / efek |
|---|---|---|
| `FoodPreset::apply` | `app(FoodPreset::class)->apply($item)` dengan import Services\FoodPreset | Item, menggabungkan tracking batch dan sertifikat halal; tidak membuat sertifikat. |
| `MadeToOrderTrigger::handle` | `app(MadeToOrderTrigger::class)->handle($document, 'posted')` | Collection<RecipeBatch>, atau collection kosong untuk status lain/line tanpa recipe_version_id. Hook otomatis pada alur food_order; jangan dipanggil ulang oleh controller. |
| `FoodAccountingGuard::assertDisabled` | Tanpa parameter | void atau DomainException; guard internal. |

Untuk MTO, metadata line sumber memuat `recipe_version_id` dan item harus sama
dengan output recipe. Hook membuat planned batch `MTO-{documentId}-{lineNo}`,
bukan otomatis menyelesaikan produksi. Pasangan source document/line mencegah
batch duplikat pada retry. Prasyarat tracking Food berlaku hanya setelah preset
diterapkan pada item yang dipilih.
