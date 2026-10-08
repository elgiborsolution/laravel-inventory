# Laravel Inventory Asset

## Kegunaan dan fitur

**Kegunaan:** Peminjaman aset perusahaan yang perlu dilacak per serial.

**Fungsi dan fitur:** Checkout/check-in, reservasi aset, pencegahan alokasi aktif ganda, tanggal jatuh tempo, dan notifier keterlambatan yang dapat diganti.

**Contoh penggunaan:** Meminjamkan laptop atau alat kerja kepada pegawai lalu mencatat pengembaliannya.

**Batas dan integrasi host:** Checkout/check-in tidak mengubah stok on-hand. Status peminjaman mengikuti data checkout/alokasi, bukan status serial on_loan. Host mengatur penerima, UI, dan jadwal notifikasi; jangan alokasikan serial yang sama melalui Asset dan Library.

Lihat [perbandingan sembilan modul](../../docs/INSTALLATION.md#51-kegunaan-fungsi-dan-fitur-sembilan-modul)
untuk memilih modul yang sesuai.

## Activation

Bundled installation: install Core once, then run `php artisan config:clear`,
`php artisan vendor:publish --tag=inventory-asset-config`, and
`php artisan migrate`. Check `php artisan inventory:modules`. No separate module
download is needed. Core registers the module provider on the next boot when
its host config file exists; do not register a module provider manually for this
activation flow. See [installation and upgrade](../../docs/INSTALLATION.md) for
config cache, worker restarts, upgrades, and deactivation.

## Technical behavior

`elgibor-solution/laravel-inventory-asset` is a bundled module that
depends only on Inventory Core and owns all `inva_*` tables.

`AssetPreset` enables serialized receipt/issue tracking and marks an Item as
checkout-managed. Check-out locks the Core Serial first, performs availability
and duplicate checks in the same transaction, creates exactly one Core
Reservation, and inserts a portable unique active-allocation row. Check-in
releases that Reservation and deletes the active-allocation row. Neither action
posts a stock document or changes on-hand quantity.

Loan state is authoritative in `inva_checkouts`. Core's Serial status remains
`in_stock` because Core currently has no non-ledger `on_loan` serial state; the
unique active allocation and checkout status are the availability authority.

Overdue is derived from an active checkout's `due_at` at read time. The
`inventory-assets:detect-overdue` command invokes the replaceable
`OverdueNotifier` only and never mutates checkout, reservation, serial, or stock
state. Projects may schedule this command using Laravel's normal scheduler.

The Asset package intentionally registers no Document Type, MovementPolicy, or
CostingDriver and works with accounting and approval bridges disabled.
