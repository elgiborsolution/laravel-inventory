# Inventory Automotive

## Kegunaan dan fitur

**Kegunaan:** Pencatatan pemakaian sparepart untuk work order dan kendaraan.

**Fungsi dan fitur:** WorkOrderParts::issue untuk pengeluaran sparepart melalui Core, laporan pemakaian per work order/kendaraan/barang/serial, serta preset serial dan sertifikat compliance.

**Contoh penggunaan:** Mengeluarkan sparepart untuk servis kendaraan dan melaporkan kuantitas serta biaya pemakaiannya.

**Batas dan integrasi host:** Master kendaraan dan work order dimiliki host; tidak ada migration tambahan. Pengeluaran Automotive ditolak ketika accounting terkait aktif atau bridge bukan NullAccountingBridge karena service code belum terverifikasi.

Lihat [perbandingan sembilan modul](../../docs/INSTALLATION.md#51-kegunaan-fungsi-dan-fitur-sembilan-modul)
untuk memilih modul yang sesuai.

## Activation

Bundled installation: install Core once, then run `php artisan config:clear`,
`php artisan vendor:publish --tag=inventory-automotive-config`, and
`php artisan migrate`. Check `php artisan inventory:modules`. No separate module
download is needed. Core registers the module provider on the next boot when
its host config file exists; do not register a module provider manually for this
activation flow. See [installation and upgrade](../../docs/INSTALLATION.md) for
config cache, worker restarts, upgrades, and deactivation.

## Technical behavior

Bundled module using Inventory Core. No Work Order or vehicle tables are owned
here. Publishing `inventory-automotive-config` activates the module on the next boot.

Apply AutomotivePreset to stock Items to require receipt/issue serials and a
valid `compliance` Certificate attached to the Core Serial. Existing tracking
settings and certificate requirements are preserved. Certificate validity is
checked against the transaction date, including issued_at and expires_at.

WorkOrderParts::issue accepts an external ID, organization, transaction date,
Work Order type/ID, vehicle type/ID, and Core LineData entries. It delegates
to InventoryManager using work_order_parts_issue. Core owns quantity validation,
costing, ledger, approval, transactions and idempotency.

Work Order references use Document.source_type/source_id; vehicle references
use Document.party_type/party_id. Type strings may be external aliases and IDs
may be strings; no local model or foreign key is required.

PartUsageReport::query requires organizationId and supports Work Order, vehicle,
Item and Serial filters. Use both type and ID to distinguish polymorphic
references. Call get() or paginate() on the returned query. Quantities and costs
come from posted outbound ledger rows grouped by document line, so pending
approval documents are excluded and split cost layers do not duplicate usage.

Accounting is deliberately fail-closed: verified Automotive service codes are
not available. Enabling Core/Automotive accounting or binding a non-null bridge
blocks posting before stock effects, including direct Core calls and approval
resume. Adding a configuration mapping alone does not enable accounting.

Serial receipt/issue availability semantics remain those of Core; this package
does not introduce serial-specific costing or a separate serial lifecycle.

No new indexes or migrations are shipped. The acceptance suite runs SQLite
EXPLAIN QUERY PLAN against the actual report query. A production index decision
requires representative data and EXPLAIN on the host MySQL/PostgreSQL database;
the small SQLite fixture is not evidence for a production performance claim.


## Contoh service

Ikuti [konvensi payload, hasil, dan error](../../docs/SALES_PURCHASING_INTEGRATION.md#konvensi-contoh-dan-error).
Contoh JSON model adalah proyeksi field terpilih; semua ID/nilai ilustratif dan
memerlukan data master yang sesuai. Contoh method terpisah bukan instruksi untuk
menjalankan seluruh mutasi berulang pada data produksi.

Prasyarat: stok sparepart tersedia; host sudah memiliki work order dan kendaraan.
AutomotivePreset opsional diterapkan pada item yang memerlukan serial/compliance;
jika diterapkan, setiap LineData harus mengikuti kebutuhan serial/sertifikatnya.
Accounting Core/Automotive harus nonaktif dan memakai NullAccountingBridge.

```php
use ESolution\Inventory\DTO\LineData;
use ESolution\InventoryAutomotive\Services\WorkOrderParts;
use ESolution\InventoryAutomotive\Services\PartUsageReport;

$document = app(WorkOrderParts::class)->issue(
    externalId: 'WO-001-PARTS-1', organizationId: 1, trxDate: '2026-10-08',
    workOrderType: 'work_order', workOrderId: 'WO-001',
    vehicleType: 'vehicle', vehicleId: 'VH-001',
    lines: [new LineData(itemId: 1, uomId: 1, warehouseId: 1, qty: 2)],
);
$result = ['id' => $document->id, 'document_type' => $document->document_type,
    'status' => $document->status->value];
$rows = app(PartUsageReport::class)->query(
    organizationId: 1, workOrderType: 'work_order', workOrderId: 'WO-001',
)->get();
```

Proyeksi dokumen:

```json
{"id":1,"document_type":"work_order_parts_issue","status":"posted"}
```

Contoh row query dengan qty/amount dinormalisasi ke angka, jika biaya 40000/unit:

```json
[{"document_id":1,"external_id":"WO-001-PARTS-1","work_order_type":"work_order","work_order_id":"WO-001","vehicle_type":"vehicle","vehicle_id":"VH-001","document_line_id":1,"item_id":1,"serial_id":null,"qty":2,"amount":80000}]
```

| Method | Input / hasil | Efek dan error |
|---|---|---|
| `AutomotivePreset::apply` | Item -> Item; `app(AutomotivePreset::class)->apply($item)` dengan import namespace Services modul | Menyimpan aturan serial dan compliance tanpa menghapus tracking lama. |
| `WorkOrderParts::issue` | Semua parameter contoh wajib; lines=list<LineData> | Document Core, termasuk waiting_approval jika approval diperlukan. Mengurangi stok dan menghitung biaya setelah posted. |
| `PartUsageReport::query` | organizationId:int wajib; workOrderType/Id, vehicleType/Id:?string=null; itemId/serialId:?int=null | Query Builder; panggil get() atau paginate(20), bukan serialisasi builder. Tanpa hasil: collection kosong. |

Gunakan type dan ID bersama pada filter polymorphic. Report hanya menghitung ledger
outbound yang posting_completed_at terisi dan tidak menggandakan jumlah saat satu
line memakai beberapa cost layer. Retry issue mengikuti externalId Core; konflik
payload ditolak. Referensi work order/kendaraan kosong, compliance tidak valid,
serial salah lokasi, atau accounting aktif dapat menggagalkan posting.
