# Sales and Purchasing Integration

Inventory Core does not own Sales Order or Purchase Order tables. Application
code identifies those records with `sourceType` and `sourceId`, while
`partyType` and `partyId` independently identify the customer or supplier.

## Sales reservation lifecycle

Reserve stock when the application confirms a Sales Order:

```php
use ESolution\Inventory\Facades\Inventory;

$reservation = Inventory::reserve(
    itemId: $line->item_id,
    qty: $line->qty,
    warehouseId: $order->warehouse_id,
    sourceType: $order::class,
    sourceId: (string) $order->getKey(),
);
```

Release its remaining quantity when demand is cancelled:

```php
Inventory::release($reservation->id);
```

Reservation methods change availability only. They do not write Ledger or Cost
Layer rows and do not invoke Accounting or Approval bridges.

## Atomic fulfillment

Attach every reservation consumption to the corresponding outbound document
line. Posting, costing, Ledger writes, Stock Card refresh, and reservation
consumption then share the Posting Engine transaction:

```php
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\DTO\ReservationConsumptionData;
use ESolution\Inventory\Facades\Inventory;

$document = Inventory::post(new DocumentData(
    type: 'sales_delivery',
    organizationId: $order->organization_id,
    trxDate: now()->toDateString(),
    externalId: "shipment:{$shipment->id}",
    sourceType: $order::class,
    sourceId: (string) $order->getKey(),
    partyType: $order->customer::class,
    partyId: (string) $order->customer->getKey(),
    lines: [
        new LineData(
            itemId: $line->item_id,
            uomId: $line->uom_id,
            warehouseId: $order->warehouse_id,
            qty: $shippedQty,
        ),
    ],
    reservationConsumptions: [
        new ReservationConsumptionData(
            reservationId: $reservation->id,
            lineNo: 1,
            qty: $shippedQty,
            idempotencyKey: "shipment-line:{$shipmentLine->id}",
        ),
    ],
));
```

The fulfillment key is unique within one Reservation. Retrying the same
document and payload returns the existing result; reusing a fulfillment key
with a different quantity or line is rejected.

The Reservation item, warehouse, `source_type`, and `source_id` must match the
linked Goods Issue line and Document. Linked quantities cannot exceed either
the Reservation remainder or the Document Line quantity.

When Approval pauses the Document, the consumption instruction remains in the
Document metadata and no Reservation progress changes. Approval resume executes
the stock effects and linked consumption exactly once in the same transaction.

Partial shipments use a new Document `externalId` and a new fulfillment key for
each shipment. A Reservation remains `active` until all quantity is consumed or
released.

Walk-in sales remain valid: omit `reservationConsumptions` and post an ordinary
Goods Issue.

## Purchasing

Purchasing never uses Reservation. When goods physically arrive, post a normal
`purchase_receipt` with the Purchase Order as source and the Supplier as party.
PO approval and workflow remain application-owned.

## Availability

Read current warehouse availability with:

```php
$availability = Inventory::availability($itemId, $warehouseId);

$availability->onHandQty;
$availability->reservedQty;
$availability->lockedQty;
$availability->availableQty();
```

The formula is `on hand - active reservation remainder - active stock locks`.
Reservations are permissive by default to support backorders. To reject a
Reservation that exceeds current availability, add `reservation` to:

```php
'policies' => [
    'negative_stock' => [
        'mode' => 'block',
        'applies_to' => ['goods_issue', 'reservation'],
    ],
],
```

Application authorization and all SO/PO schema, status, approval, and business
rules remain outside Inventory Core.


## Business calculations

Perhitungan berikut mengimplementasikan alur pembelian, penjualan, retur, dan
opname untuk persediaan perpetual. Pilih `inventory.costing.default_method =
moving_average` (atau `costing_method` pada item). Default tetap FIFO. Moving
Average memakai nilai ledger tersisa dibagi kuantitas dalam scope gudang/rak;
lapisan fisik tetap dipilih menurut aturan tracking dan tidak menjadi sumber HPP
Moving Average. Histori ledger tidak diubah. Weighted Average masih memiliki
acceptance TODO tersendiri; jangan menganggap semua pilihan costing sudah setara.

### Purchase and sales prices

`LineData` menambahkan parameter opsional `transactionPrice` (harga satuan sebelum
diskon) dan `discountPerUnit` (diskon per satuan, default 0). Harga bersih adalah
harga dikurangi diskon. Untuk `purchase`/`purchase_receipt`, harga bersih menjadi
`unitCost`; bila keduanya diberikan, nilainya harus sama. Harga/diskon harus finite,
non-negatif, dan diskon tidak boleh melebihi harga. Metadata pricing disimpan oleh
Core; caller tidak boleh mengisi `_inventory_pricing` atau `_stock_count` langsung.

```php
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Facades\Inventory;

Inventory::post(new DocumentData(
    type: 'purchase_receipt',
    organizationId: $organizationId,
    trxDate: '2026-10-01',
    externalId: 'PI-001',
    lines: [new LineData(
        itemId: $itemId, uomId: $uomId, warehouseId: $warehouseId,
        qty: 10, transactionPrice: 42000, discountPerUnit: 2000,
    )],
));

// Setelah penerimaan kedua 10 unit @ 50000: saldo 20 unit senilai 900000.
Inventory::post(new DocumentData(
    type: 'sale',
    organizationId: $organizationId,
    trxDate: '2026-10-03',
    externalId: 'SI-001',
    lines: [new LineData(
        itemId: $itemId, uomId: $uomId, warehouseId: $warehouseId,
        qty: 5, transactionPrice: 60000,
    )],
));
// HPP 225000; sisa 15 unit / 675000; laba kotor 75000.
```

Harga adalah nilai bersih pajak yang ditetapkan host; Core tidak menghitung PPN,
diskon invoice bertingkat, ongkir, pembayaran, atau status invoice. Host memanggil
posting ketika invoice memenuhi aturan bisnisnya (misalnya `paid` untuk penjualan
atau `final` untuk retur), dan memperbarui realisasi/status PO dalam transaksi
aplikasinya. Gunakan externalId stabil agar retry tidak menggandakan stok.

### Transaction stock card

```php
$rows = Inventory::stockCard($itemId, $warehouseId);
$rowsForRack = Inventory::stockCard($itemId, $warehouseId, $storageLocationId);
```

API tersebut membaca ledger dan menghasilkan satu baris per document line/arah,
termasuk tanggal/ref, qty, harga/diskon/nett, total transaksi, HPP, laba per unit/total,
saldo qty/nilai, rata-rata saldo, serta omzet/rata-rata harga jual berjalan.
Beberapa lapisan FIFO pada satu baris penjualan tidak menggandakan omzet.
`qty` positif dengan `direction`; `signed_qty` tersedia untuk tampilan bertanda.

- `sale` dan `sales_delivery` dihitung sebagai penjualan. `goods_issue` generik,
  retur, dan opname tidak dihitung sebagai omzet/laba penjualan.
- `average_cost` pada baris penjualan bernilai 0 untuk tampilan;
  `balance_average_cost` tetap menyimpan rata-rata saldo yang sebenarnya.
- `cogs`/`out_amount` mencatat biaya keluar; laba transaksi non-penjualan bernilai 0.
- Harga jual yang tidak diberikan menghasilkan `total_trx`/laba null, bukan laba
  negatif palsu. Omzet berjalan juga null sejak ada penjualan tanpa data harga.
  Harga pembelian lama dapat dibaca dari `unit_cost`.
- Bonus menambah/mengurangi qty fisik dan HPP tetapi tidak menambah omzet tagihan.
- Urutan mengikuti ID ledger (urutan posting), bukan sortir ulang tanggal transaksi.
  Tanggal mundur tidak menghitung ulang HPP histori. Saldo setelah baris harus dibaca
  dalam urutan keluaran API. `inv_stock_cards` tetap menjadi ringkasan harian saat
  posting; laporan detail dihitung dari ledger tanpa migration/backfill baru.
- Biaya rak pada scope costing gudang adalah kontribusi nilai mutasi lokasi,
  bukan metode valuasi rak independen. Pilih scope `rack` untuk costing per rak.

### Stock count

```php
Inventory::post(new DocumentData(
    type: 'stock_opname',
    organizationId: $organizationId,
    trxDate: '2026-10-04',
    externalId: 'SO-001',
    lines: [new LineData(
        itemId: $itemId, uomId: $uomId, warehouseId: $warehouseId,
        qty: 12, // hasil fisik, BUKAN selisih
    )],
));
```

`stock_count` adalah alias. Selisih dihitung terhadap saldo saat posting, atau saat
resume setelah approval, di bawah lock item yang sama dengan posting biasa.
Selisih negatif memakai costing pengeluaran; selisih positif memakai unitCost
caller atau rata-rata saldo yang masih ada. Jika saldo belum ada, gain membutuhkan
unitCost eksplisit (boleh nol). Hasil hitung nol mengeluarkan seluruh stok; selisih
nol tidak membuat ledger/jurnal. Audit hitungan tersimpan pada metadata line
`_stock_count` (`counted_qty`, `system_qty`, `difference`); qty line setelah posting
menjadi besar mutasi absolut. Retry externalId yang sama tidak menghitung ulang.

Dukungan saat ini: stock tanpa tracking, base UOM, tanpa bonus/custom movement
policy. Jangan campurkan hitungan total gudang dan rak untuk item yang sama atau
mengulang item/lokasi dalam satu dokumen. Batch/serial count dan pembekuan sesi
opname belum disediakan. Host memastikan hitungan tidak kedaluwarsa selama approval.
Dokumen opname lama yang sudah posted tidak otomatis dikonversi menjadi adjustment.

### Returns, accounting, and compatibility

`supplier_return`/`purchase_return` mengurangi stok pada nilai buku metode costing.
Berikan `transactionPrice` dan diskon dari invoice asal untuk nilai tagihan retur;
harga itu tidak mengganti HPP. Laporan tidak mengakui retur supplier sebagai omzet.
Accounting menghitung selisih refund terhadap nilai buku melalui mapping eksplisit;
lihat [Accounting Bridge](ACCOUNTING_BRIDGE.md#adjustment-count-and-supplier-return-mappings).
Retur pelanggan tetap memerlukan unitCost yang disediakan host; pemilihan invoice
asal dan batas kuantitas retur adalah tanggung jawab aplikasi.

Moving Average memerlukan saldo tidak negatif dan menolak pengeluaran melebihi
saldo meskipun kebijakan FIFO `negative_stock.mode = allow`. Aturan ini mencegah
penilaian stok negatif yang belum disepakati dokumen bisnis. Selesaikan saldo negatif
lama sebelum beralih metode. Transfer/reversal standar masih dibatasi FIFO seperti
[dukungan transfer/reversal](TRANSFER_REVERSAL.md); patch ini tidak memperluasnya.

Tidak ada migration baru. Pemanggil LineData lama tetap valid; payload idempotency
tanpa harga/diskon tambahan mempertahankan bentuk sebelumnya. Rekonsiliasi data lama
sebelum mengaktifkan Moving Average: transaksi yang dahulu diposting sebagai FIFO
tidak diubah atau dikoreksi otomatis.
