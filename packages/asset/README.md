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


## Contoh service

Ikuti [konvensi payload, hasil, dan error](../../docs/SALES_PURCHASING_INTEGRATION.md#konvensi-contoh-dan-error).
Contoh JSON model adalah proyeksi field terpilih; semua ID/nilai ilustratif dan
memerlukan data master yang sesuai. Contoh method terpisah bukan instruksi untuk
menjalankan seluruh mutasi berulang pada data produksi.

Prasyarat: modul aktif, item sudah diberi AssetPreset, serial `in_stock` di gudang,
serta saldo tersedia >=1. CheckoutData wajib: checkoutNo:string, serialId:int,
warehouseId:int, borrowerType/string dan borrowerId/string nonkosong. Opsional:
checkedOutAt:?string=null (sekarang), dueAt:?string=null, meta:array=[]. dueAt bila
ada harus setelah waktu checkout. Semua referensi peminjam berasal dari host.

```php
use ESolution\Inventory\Models\Item;
use ESolution\InventoryAsset\DTO\CheckoutData;
use ESolution\InventoryAsset\Services\AssetPreset;
use ESolution\InventoryAsset\Services\AssetCheckoutService;
use ESolution\InventoryAsset\Services\OverdueService;

$item = app(AssetPreset::class)->apply(Item::findOrFail(1));
$service = app(AssetCheckoutService::class);
$loan = $service->checkout(new CheckoutData(
    checkoutNo: 'AS-001', serialId: 10, warehouseId: 1,
    borrowerType: 'employee', borrowerId: 'EMP-01',
    checkedOutAt: '2026-10-08 09:00:00', dueAt: '2026-10-15 17:00:00',
));
$result = ['id' => $loan->id, 'checkout_no' => $loan->checkout_no,
    'status' => $loan->status, 'reservation_id' => $loan->reservation_id];
```

Proyeksi hasil checkout:

```json
{"id":1,"checkout_no":"AS-001","status":"active","reservation_id":1}
```

| Service/method | Input contoh | Hasil dan efek |
|---|---|---|
| `AssetPreset::apply` | Item model seperti di atas | Item dengan tracking checkout/serial digabung dan disimpan; tidak menambah stok. |
| `AssetCheckoutService::checkout` | CheckoutData di atas | AssetCheckout + relasi serial/reservation/activeAllocation; membuat reservasi 1, on-hand tetap. |
| `checkin` | `$service->checkin($loan->id, '2026-10-12 10:00:00')` | AssetCheckout dengan status `checked_in`; melepas reservasi dan alokasi. Waktu opsional null=sekarang. |
| `OverdueService::detect` | `app(OverdueService::class)->detect(now())` | Collection<AssetCheckout>, misalnya `[]` jika tidak terlambat; memanggil notifier untuk setiap pinjaman aktif yang due_at < waktu. |
| `OverdueNotifier::notify` | Implementasi host menerima AssetCheckout | void; pengiriman notifikasi dilakukan implementasi host. |

Error contoh: `Asset serial already has an active allocation.` atau
`Asset checkout due date must be after checkout time.` Retry checkoutNo yang sama
memeriksa serial/gudang/peminjam, tetapi bukan perubahan dueAt/meta; gunakan API
aplikasi sendiri untuk perubahan kontrak pinjaman. Checkin yang sudah selesai
mengembalikan hasil lama. detect dapat mengirim notifikasi lagi setiap dipanggil;
notifier host perlu deduplikasi. Jangan gunakan Asset dan Library untuk serial sama.
