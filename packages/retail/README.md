# Laravel Inventory Retail

## Kegunaan dan fitur

**Kegunaan:** Toko dengan varian produk dan barang titipan supplier (konsinyasi).

**Fungsi dan fitur:** Membentuk kombinasi ukuran/warna sebagai Core Item terpisah; mengatur terms konsinyasi per barang/lokasi; mencatat kewajiban settlement dari barang terjual. POS memakai posting Core, sedangkan e-commerce dapat memakai reservasi sebelum fulfillment.

**Contoh penggunaan:** Toko pakaian membuat SKU untuk setiap ukuran dan warna, lalu melacak penjualan barang titipan.

**Batas dan integrasi host:** Harga, diskon, kasir, storefront, pembayaran supplier, dan jurnal settlement tetap ditangani aplikasi host.

Lihat [perbandingan sembilan modul](../../docs/INSTALLATION.md#51-kegunaan-fungsi-dan-fitur-sembilan-modul)
untuk memilih modul yang sesuai.

## Activation

Bundled installation: install Core once, then run `php artisan config:clear`,
`php artisan vendor:publish --tag=inventory-retail-config`, and
`php artisan migrate`. Check `php artisan inventory:modules`. No separate module
download is needed. Core registers the module provider on the next boot when
its host config file exists; do not register a module provider manually for this
activation flow. See [installation and upgrade](../../docs/INSTALLATION.md) for
config cache, worker restarts, upgrades, and deactivation.

## Technical behavior

`elgibor-solution/laravel-inventory-retail` is an optional vertical for
`elgibor-solution/laravel-inventory`. It owns only the
`ESolution\InventoryRetail` namespace and `invr_*` tables.

## Configuration after activation

Enable Consignment in `config/inventory-retail.php`:

```php
'consignment' => [
    'enabled' => true,
    'settlement' => ['periodicity' => 'monthly'],
],
```

## Variant matrix

Create a Product Family, its axes, and values, then generate the cartesian
matrix. Every result is a distinct Core Item with independent Ledger, Costing,
Stock Card, and Reservation state.

```php
$items = app(VariantMatrixGenerator::class)->generate($family);
```

Generation is deterministic and retry-safe. Existing matching combinations are
returned; an SKU collision with unrelated Item data is rejected. Item inserts
are chunked according to `inventory-retail.variant_matrix.insert_chunk_size`.

## Consignment

Configure supplier terms at Item scope or at a more specific storage location:

```php
$term = app(ConsignmentTermsService::class)->configure(
    itemId: $itemId,
    supplierPartyType: Supplier::class,
    supplierPartyId: (string) $supplierId,
    locationId: null,
    referenceUnitCost: 5000,
    periodicity: 'monthly',
);
```

The service registers the Core `inventory_model=consignment` policy override.
Location overrides win over Item-only overrides. A Consignment receipt:

- posts ordinary physical Ledger and Stock Card quantities;
- may retain a reference cost for reporting;
- reports `ownedValue = 0` through `ConsignmentInventoryService`;
- does not call Core's Accounting Bridge for ownership transfer.

A `sales_delivery` remains an ordinary Goods Issue. After commit, Retail records
one `invr_consignment_settlements` obligation per sold Document Line. Retrying a
sale cannot duplicate it. Retail never posts a settlement journal.

Projects may mark obligations settled without accounting side effects:

```bash
php artisan inventory-retail:consignment:settle --through=2026-09-30
```

## POS and E-Commerce

- POS posts an ordinary `sales_delivery` with no Reservation.
- E-Commerce uses Core's existing Phase 4 flow: reserve at checkout, then post
  `sales_delivery` with `ReservationConsumptionData` at fulfillment.

Retail contains no pricing, discount, till, storefront, Sales Order, Purchase
Order, supplier accounting, or sibling-vertical logic.


## Contoh service

Ikuti [konvensi payload, hasil, dan error](../../docs/SALES_PURCHASING_INTEGRATION.md#konvensi-contoh-dan-error).
Contoh JSON model adalah proyeksi field terpilih; semua ID/nilai ilustratif dan
memerlukan data master yang sesuai. Contoh method terpisah bukan instruksi untuk
menjalankan seluruh mutasi berulang pada data produksi.

Prasyarat varian: ProductFamily aktif dengan base_sku/base_name, kategori, UOM,
VariantAxis dan VariantAxisValue sudah dibuat. Prasyarat konsinyasi:
`inventory-retail.consignment.enabled=true`. Identitas supplier dimiliki host.

```php
use ESolution\InventoryRetail\Models\ProductFamily;
use ESolution\InventoryRetail\Services\VariantMatrixGenerator;
use ESolution\InventoryRetail\Services\ConsignmentTermsService;
use ESolution\InventoryRetail\Services\ConsignmentInventoryService;

$family = ProductFamily::findOrFail(1); // base_sku=TSHIRT; axis ukuran S/M, warna RED
$items = app(VariantMatrixGenerator::class)->generate($family);
$result = $items->map(fn($item) => ['id' => $item->id, 'sku' => $item->sku])->all();
$terms = app(ConsignmentTermsService::class);
$term = $terms->configure(1, 'supplier', 'SUP-001', referenceUnitCost: 5000, periodicity: 'monthly');
// Setelah penerimaan Core 10 unit pada item 1 dan gudang 1:
$position = app(ConsignmentInventoryService::class)->position(1, 1);
```

Proyeksi varian bila axis diurutkan ukuran lalu warna:

```json
[{"id":1,"sku":"TSHIRT-S-RED"},{"id":2,"sku":"TSHIRT-M-RED"}]
```

DTO ConsignmentPosition untuk stok fisik 10:

```json
{"physicalQty":10,"referenceValue":50000,"ownedValue":0}
```

| Method | Parameter / contoh | Hasil dan efek |
|---|---|---|
| `VariantMatrixGenerator::generate` | family:ProductFamily; axisValueSets:?array=null. Contoh subset `generate($family, [[11,12],[21]])` dengan ID value valid tiap axis | Collection<Item>; membuat SKU kombinasi, link, dan master item tanpa stok. Default mengambil seluruh value. |
| `ConsignmentTermsService::configure` | itemId:int, supplierPartyType/Id:string; locationId=null, referenceUnitCost=null, periodicity=null (config monthly) | ConsignmentTerm dan policy override; upsert per item/lokasi. Periodicity per_sale/weekly/monthly. |
| `resolve` | `$terms->resolve(1, 10)`; locationId=null | ConsignmentTerm atau null; lokasi spesifik menang atas item. |
| `ConsignmentInventoryService::position` | itemId/warehouseId:int, locationId=null | DTO seperti JSON; membaca saldo dan reference cost terms, bukan valuasi kepemilikan. |
| `SettlementRecorder::handle` | DocumentPosted event, dipanggil listener setelah posting | void; mencatat obligation per line penjualan konsinyasi, tidak membuat jurnal. |

Varian tanpa axis/value atau SKU berbenturan ditolak; generate ulang kombinasi sama
mengembalikan item yang sama. configure mengubah terms pada scope yang sama,
bukan menolak perubahan sebagai konflik retry. position tanpa terms aktif melempar
`No active Consignment terms exist for this item and location.` Settlement listener
mendeduplikasi per line; jangan memanggilnya lagi dari controller. Command
`inventory-retail:consignment:settle --through=YYYY-MM-DD` menandai kewajiban settled,
tidak mentransfer uang. POS dan fulfillment tetap memakai Core, bukan API kasir baru.
