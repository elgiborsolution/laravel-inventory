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
