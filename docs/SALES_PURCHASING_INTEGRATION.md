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


## Panduan input dan hasil service

Bagian ini adalah referensi service PHP, bukan daftar endpoint HTTP. Gunakan
`ESolution\Inventory\Facades\Inventory` atau `app(InventoryManager::class)`.
Contoh ID harus diganti dengan master pada database host. PHP minimal 8.2,
migration Core selesai, dan hak akses organisasi/gudang diperiksa oleh host.
Contoh tanpa penjelasan khusus menggunakan Accounting/Approval nonaktif.

### Konvensi contoh dan error

- JSON input di sini merupakan bentuk data aplikasi sebelum dikonversi ke DTO;
  service tidak menerima JSON mentah. Nama parameter DTO menggunakan camelCase.
- Model Eloquent dikembalikan sebagai objek, bukan response HTTP. JSON hasil
  bertanda **proyeksi** hanya memuat field yang dipilih pada contoh PHP, bukan
  keseluruhan `toArray()`. ID ilustratif bukan ID tetap. Kolom decimal Eloquent
  dapat berupa string; cast eksplisit pada proyeksi menentukan tipe JSON.
- DTO hasil memakai properti camelCase; array kartu stok memakai snake_case.
- `void` berarti tidak ada nilai hasil; jangan mengharapkan `{success: true}`.
- `InvalidArgumentException`: bentuk/nilai input ditolak; `DomainException`:
  aturan bisnis dilanggar; `ModelNotFoundException`: ID tidak ditemukan;
  `QueryException`: constraint/koneksi database. Error bridge dapat diteruskan.
  Pesan contoh mengikuti implementasi saat ini, bukan kode error API stabil.
- Untuk HTTP, host membuat FormRequest/Resource dan memetakan exception ke status
  yang sesuai. Jangan mengekspor seluruh metadata internal atau detail SQL.

### Parameter posting

`Inventory::post(DocumentData $data)` mengembalikan `Document` dengan relasi
`lines`. `PostingEngine::post()` adalah implementasi di bawah API ini.

| DocumentData | Tipe / default | Fungsi dan aturan |
|---|---|---|
| `type` | string, wajib | Jenis terdaftar: misalnya purchase_receipt, sales_delivery, supplier_return, stock_opname. |
| `organizationId` | int, wajib | ID organisasi Core; host memeriksa akses. |
| `trxDate` | string, wajib | Tanggal `YYYY-MM-DD`; validasi tanggal dilakukan host. |
| `lines` | list<LineData>, wajib | Minimal satu baris. |
| `externalId` | ?string = null | Kunci retry bersama organizationId dan sourceType; gunakan nilai stabil. Tanpa ini tidak ada deduplikasi dokumen. |
| `sourceType`, `sourceId` | string = inventory, ?string = null | Referensi invoice/order host; wajib cocok dengan reservasi untuk fulfillment. |
| `partyType`, `partyId` | ?string = null | Referensi customer/supplier/kendaraan eksternal. |
| `meta` | array = [] | Metadata aplikasi; jangan menulis context internal package. |
| `additionalJournalLines` | list<array> = [] | Baris keuangan milik host; lihat Accounting Bridge. |
| `accountingServiceCode` | ?string = null | Pilih kode yang diizinkan konfigurasi bila terdapat beberapa pilihan. |
| `tenantIdentity` | mixed = null | Identitas tenant untuk bridge; bukan pengganti otorisasi. |
| `approvalAction` | string = create | Action yang dikirim ke Approval. |
| `approvalData`, `approvalMetadata` | array = [] | Data dan metadata untuk workflow eksternal. |
| `reservationConsumptions` | list<ReservationConsumptionData> = [] | Konsumsi reservasi atomik dengan posting keluar. |

| LineData | Tipe / default | Fungsi dan aturan |
|---|---|---|
| `itemId`, `uomId`, `warehouseId` | int, wajib | ID barang, satuan, dan gudang Core. |
| `qty` | float, wajib | Positif; khusus count adalah fisik >= 0. |
| `storageLocationId` | ?int = null | Lokasi fisik; wajib bila costing scope rack. |
| `qtyBonus` | float = 0 | Non-negatif, menambah qty fisik tetapi tidak menambah nilai tagihan. |
| `unitCost` | ?float = null | Biaya masuk non-negatif; pembelian dapat menurunkannya dari harga bersih. |
| `batchId`, `serialId` | ?int = null | Identitas tracking sesuai item. Serial mewakili tepat satu unit termasuk bonus. |
| `meta` | array = [] | Metadata baris; `_inventory_pricing` dan `_stock_count` dicadangkan. |
| `transactionPrice` | ?float = null | Harga per unit sebelum diskon; bukan otomatis termasuk pajak. |
| `discountPerUnit` | float = 0 | Harus non-negatif, membutuhkan transactionPrice, tidak melebihi harga. |

Payload aplikasi untuk penerimaan:

```json
{
  "type": "purchase_receipt",
  "organizationId": 1,
  "trxDate": "2026-10-08",
  "externalId": "PI-001",
  "lines": [{"itemId": 1, "uomId": 1, "warehouseId": 1, "qty": 10, "transactionPrice": 42000, "discountPerUnit": 2000}]
}
```

Konversi eksplisit setelah validasi/otorisasi host:

```php
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Facades\Inventory;

$document = Inventory::post(new DocumentData(
    type: $input['type'],
    organizationId: $input['organizationId'],
    trxDate: $input['trxDate'],
    externalId: $input['externalId'],
    lines: array_map(fn(array $line) => new LineData(
        itemId: $line['itemId'], uomId: $line['uomId'],
        warehouseId: $line['warehouseId'], qty: $line['qty'],
        transactionPrice: $line['transactionPrice'],
        discountPerUnit: $line['discountPerUnit'] ?? 0,
    ), $input['lines']),
));
$result = [
    'id' => $document->id,
    'document_type' => $document->document_type,
    'external_id' => $document->external_id,
    'status' => $document->status->value,
    'lines' => $document->lines->map(fn($line) => [
        'item_id' => (int) $line->item_id,
        'qty' => (float) $line->qty,
        'unit_cost' => (float) $line->unit_cost,
    ])->all(),
];
```

Proyeksi hasil penerimaan berhasil:

```json
{"id":1,"document_type":"purchase_receipt","external_id":"PI-001","status":"posted","lines":[{"item_id":1,"qty":10,"unit_cost":40000}]}
```

Posting membuat dokumen, ledger, cost layer, ringkasan kartu stok, dan bila aktif
memanggil bridge. Saat approval diperlukan, hasil berstatus `waiting_approval`;
belum ada dampak stok. Retry payload identik mengembalikan dokumen yang sama pada
mode default `return_existing`. Payload berbeda pada kunci yang sama menghasilkan
`DomainException: Idempotency key was already used with a different payload.`

### Variasi operasi melalui post

Setiap baris berikut menggunakan envelope DocumentData di atas; ganti externalId
untuk operasi baru. Hasil tetap `Document`, status umumnya `posted` atau
`waiting_approval`. Rincian nominal adalah contoh dengan saldo awal yang disebutkan.

| Kegunaan | Contoh type dan LineData | Dampak / contoh hasil |
|---|---|---|
| Penjualan | `sale`, `new LineData(1,1,1,5,transactionPrice:60000)` | Saldo 20 @ rata-rata 45000 menjadi 15; HPP 225000. |
| Pengeluaran umum | `goods_issue`, `new LineData(1,1,1,2)` | Mengurangi 2; tidak menjadi omzet pada stockCard. |
| Retur supplier | `supplier_return`, `new LineData(1,1,1,5,transactionPrice:50000)` | Nilai buku keluar 225000 pada average 45000; refund 250000 untuk accounting. |
| Retur pelanggan | `customer_return`, `new LineData(1,1,1,1,unitCost:45000)` | Masuk 1 senilai 45000; host menentukan invoice asal/biaya retur. |
| Gain eksplisit | `positive_adjustment`, `new LineData(1,1,1,2,unitCost:45000)` | Masuk 2 senilai 90000. |
| Loss eksplisit | `negative_adjustment`, `new LineData(1,1,1,2)` | Keluar 2 menurut costing aktif. |
| Hasil hitung fisik | `stock_opname`, `new LineData(1,1,1,12)` | Jika sistem 15, line setelah posting qty 3 arah out; meta count menyimpan counted 12/system 15/difference -3. |

Tracking, kekurangan stok, biaya masuk tidak valid, mapping accounting yang hilang,
dan kegagalan integrasi dapat membatalkan keseluruhan posting. Jangan memposting
adjustment kedua setelah opname otomatis. Untuk count saat saldo awal kosong,
gain memerlukan unitCost. Count tracked belum didukung.

### Reservasi dan fulfillment

Semua method berikut menghasilkan model `Reservation`; contoh hasil merupakan
proyeksi `id`, `status`, `reserved_qty`, `consumed_qty`, `released_qty`, dan accessor
`remaining_qty` (cast kuantitas ke float). Kolom accessor tidak dijamin muncul pada
serialisasi model mentah.

```php
$reservation = Inventory::reserve(1, 5, 1, 'sales_order', 'SO-001');
// Snapshot awal: reserved_qty=5, consumed_qty=0, released_qty=0, remaining_qty=5.
$reservation = Inventory::consume($reservation->id, 2, 'FULFILL-001');
// Snapshot berikutnya: consumed_qty=2, remaining_qty=3, status=active.
$reservation = Inventory::release($reservation->id, 1);
// released_qty=1, remaining_qty=2. release(id) melepas seluruh sisanya.
```

Proyeksi setelah ketiga panggilan:

```json
{"id":1,"status":"active","reserved_qty":5,"consumed_qty":2,"released_qty":1,"remaining_qty":2}
```

| Operasi | Parameter / default | Error dan retry |
|---|---|---|
| `reserve` | itemId:int, qty:float >0, warehouseId:int, sourceType/sourceId:string nonkosong | Membuat reservasi BARU setiap panggilan, bahkan source sama; deduplikasi pembuatan milik host. Stok kurang ditolak hanya jika kebijakan reservation diaktifkan. |
| `release` | id:int, qty:?float=null (seluruh sisa) | Qty harus >0 dan <= sisa. Bukan operasi berkunci; pengulangan partial release melepas lagi, full release kedua ditolak. |
| `consume` | id:int, qty:float >0, key:string 1 sampai 128 karakter, lineId:?int=null | Retry kunci sama + qty/line sama tidak menambah konsumsi; konflik ditolak. Tidak membuat ledger. |

Untuk pengiriman nyata, gunakan `reservationConsumptions` pada posting keluar
seperti contoh Atomic fulfillment; jangan consume dua kali. Setiap DTO memerlukan
`reservationId:int`, `lineNo:int` mulai 1, `qty:float`, `idempotencyKey:string`.
Source dokumen, barang, gudang, dan jumlah harus cocok. `ReservationService`
menyediakan method yang sama, tetapi facade adalah pintu masuk aplikasi.

### Availability dan kartu stok

```php
$available = Inventory::availability(1, 1);
$result = get_object_vars($available);
// DTO: onHandQty=15, reservedQty=2, lockedQty=0, availableQty=13.
$rows = Inventory::stockCard(1, 1); // lokasi opsional sebagai argumen ketiga
```

Contoh DTO availability diserialisasikan:

```json
{"onHandQty":15,"reservedQty":2,"lockedQty":0,"availableQty":13}
```

Contoh satu elemen **array asli** stockCard setelah 10 @40000 + 10 @50000, lalu
sale 5 @60000, Moving Average, tanpa reservasi/bonus/cost adjustment:

```json
{
  "document_id":3,"document_line_id":3,"date":"2026-10-03","document_ref":"SI-001",
  "description":"sale","direction":"out","qty":5,"signed_qty":-5,
  "sales_price":60000,"discount_amount":0,"nett_price":60000,"total_trx":300000,
  "in_amount":0,"out_amount":225000,"cogs":225000,"profit_unit":15000,"profit_amount":75000,
  "balance_qty":15,"balance_amount":675000,"average_cost":0,"balance_average_cost":45000,
  "running_total_sales":300000,"running_avg_sales":60000,"cost_adjustment_amount":0
}
```

Query tidak mengubah data. Tanpa ledger, stockCard menghasilkan `[]`; availability
menghitung saldo dari data yang tersedia, bukan bukti bahwa ID master valid.
`StockAvailabilityService::forItem` dan `StockCardReport::forItem` adalah service
langsung yang mendasari facade. Laporan tidak memuat pagination bawaan; host
mengatur cakupan akses dan ukuran penggunaan. Lihat aturan null pricing dan urutan
posting pada Transaction stock card di atas.

### Navigasi service lain

- [Transfer/reversal: payload dan hasil](TRANSFER_REVERSAL.md#input-dan-hasil-service).
- [Approval: hasil submit dan resume](APPROVAL_BRIDGE.md#contoh-input-dan-hasil).
- [Accounting: payload gateway](ACCOUNTING_BRIDGE.md#contoh-payload-dan-hasil-gateway).
- [Service internal dan extension points](ECOSYSTEM_RELEASE.md#referensi-service-internal).
- [Retail](../packages/retail/README.md#contoh-service), [WMS](../packages/wms/README.md#contoh-service),
  [Manufacturing](../packages/manufacturing/README.md#contoh-service), [Healthcare](../packages/healthcare/README.md#contoh-service),
  [Food](../packages/food/README.md#contoh-service), [Asset](../packages/asset/README.md#contoh-service),
  [Project](../packages/project/README.md#contoh-service), [Automotive](../packages/automotive/README.md#contoh-service),
  [Library](../packages/library/README.md#contoh-service).
