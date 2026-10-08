# Laravel Inventory Healthcare

## Kegunaan dan fitur

**Kegunaan:** Persediaan farmasi atau bahan medis yang memerlukan pengawasan batch dan kedaluwarsa.

**Fungsi dan fitur:** Preset batch/expiry, FEFO (kedaluwarsa terdekat dikeluarkan dahulu), COA (sertifikat analisis) saat pengeluaran, penerimaan kedaluwarsa dengan disposisi terkontrol, recall, dan penelusuran dokumen pengeluaran batch.

**Contoh penggunaan:** Menarik batch obat tertentu dan menelusuri dokumen yang pernah mengeluarkan batch tersebut.

**Batas dan integrasi host:** FEFO dimiliki Core dan tidak membutuhkan WMS. Preset harus diterapkan pada item terkait; modul tidak menyediakan aplikasi klinik atau rekam medis.

Lihat [perbandingan sembilan modul](../../docs/INSTALLATION.md#51-kegunaan-fungsi-dan-fitur-sembilan-modul)
untuk memilih modul yang sesuai.

## Activation

Bundled installation: install Core once, then run `php artisan config:clear`,
`php artisan vendor:publish --tag=inventory-healthcare-config`, and
`php artisan migrate`. Check `php artisan inventory:modules`. No separate module
download is needed. Core registers the module provider on the next boot when
its host config file exists; do not register a module provider manually for this
activation flow. See [installation and upgrade](../../docs/INSTALLATION.md) for
config cache, worker restarts, upgrades, and deactivation.

## Technical behavior

`elgibor-solution/laravel-inventory-healthcare` is a bundled module that
depends only on Inventory Core and owns all `invh_*` tables.

`HealthcarePreset` merges mandatory receipt batch/expiry tracking, Core FEFO,
valid COA-on-issue, and controlled expired-receipt dispositions into an Item
without removing existing tracking settings.

Deterministic FEFO is Core-owned and does not require WMS. It excludes expired,
recalled, blocked, and certificate-ineligible batches, then orders eligible
layers by non-null expiry, `expires_at`, `received_at`, and Cost Layer ID.

Expired receipts are accepted only when their line metadata declares an allowed
controlled disposition (`quarantine` or `disposal`). They remain traceable but
cannot be issued. Recall records change only their batch's availability;
`RecallService::forwardTrace()` resolves outbound Core documents through the
immutable Cost Layer and Stock Ledger links.


## Contoh service

Ikuti [konvensi payload, hasil, dan error](../../docs/SALES_PURCHASING_INTEGRATION.md#konvensi-contoh-dan-error).
Contoh JSON model adalah proyeksi field terpilih; semua ID/nilai ilustratif dan
memerlukan data master yang sesuai. Contoh method terpisah bukan instruksi untuk
menjalankan seluruh mutasi berulang pada data produksi.

Prasyarat: batch milik item, expiry dan COA sesuai aturan tracking, serta ledger
penerimaan sudah ada untuk penelusuran. Preset berlaku per item dan menyimpan
tracking; tidak membuat batch/sertifikat maupun penerimaan otomatis.

```php
use ESolution\Inventory\Models\Item;
use ESolution\InventoryHealthcare\Services\HealthcarePreset;
use ESolution\InventoryHealthcare\Services\RecallService;

$item = app(HealthcarePreset::class)->apply(Item::findOrFail(1));
$service = app(RecallService::class);
$recall = $service->recall(recallNo: 'RC-001', batchId: 10, reason: 'Quality issue');
$result = ['id' => $recall->id, 'recall_no' => $recall->recall_no,
    'batch_id' => (int) $recall->batch_id, 'status' => $recall->refresh()->status];
$documents = $service->forwardTrace($recall->id);
$references = $documents->map(fn($d) => ['id' => $d->id, 'external_id' => $d->external_id])->all();
```

Proyeksi hasil recall dan, pada blok terpisah, dokumen outbound yang pernah memakai batch:

```json
{"id":1,"recall_no":"RC-001","batch_id":10,"status":"active"}
```

```json
[{"id":3,"external_id":"DELIVERY-001"}]
```

| Method | Parameter / contoh | Hasil dan dampak |
|---|---|---|
| `HealthcarePreset::apply` | Item model | Item; menyimpan aturan batch/expiry, FEFO, COA. |
| `RecallService::recall` | recallNo:string, batchId:int, reason:string; semua wajib | Recall; batch menjadi recalled, tidak membalik ledger lama. |
| `forwardTrace` | recallId:int | Collection<Document> berurutan ID, `[]` bila tidak ada outbound. Query tidak menulis data. |
| `release` | `$service->release($recall->id)` | Recall status released; batch kembali available bila tidak ada recall aktif lain. |

Nomor/reason kosong ditolak. Nomor sama dengan batch/reason berbeda menimbulkan
`Recall number was reused with a different payload.` Satu batch tidak boleh punya
dua recall aktif. Release berulang mengembalikan record yang sudah released.
Retry recall bernomor sama tidak membuat recall baru. Validasi penerimaan/
pengeluaran tetap melalui Core; recall bukan pengiriman pesan kepada pelanggan.
