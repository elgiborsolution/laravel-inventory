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
