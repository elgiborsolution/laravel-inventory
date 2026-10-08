# Inventory Library

## Kegunaan dan fitur

**Kegunaan:** Sirkulasi peminjaman buku atau koleksi per eksemplar.

**Fungsi dan fitur:** Satu serial per copy, antrean hold, reservasi copy yang siap diambil, checkout/check-in, perpanjangan, kedaluwarsa ready hold, status terlambat, dan pencatatan denda.

**Contoh penggunaan:** Anggota memesan buku, mengambil copy yang tersedia, lalu mengembalikan atau memperpanjang pinjaman.

**Batas dan integrasi host:** Anggota berasal dari host. Sirkulasi tidak mengubah on-hand; tarif denda otomatis, pembayaran, dan jurnal tidak disediakan. Host menjadwalkan expireReadyHolds(); jangan alokasikan serial yang sama melalui Asset dan Library.

Lihat [perbandingan sembilan modul](../../docs/INSTALLATION.md#51-kegunaan-fungsi-dan-fitur-sembilan-modul)
untuk memilih modul yang sesuai.

## Activation

Bundled installation: install Core once, then run `php artisan config:clear`,
`php artisan vendor:publish --tag=inventory-library-config`, and
`php artisan migrate`. Check `php artisan inventory:modules`. No separate module
download is needed. Core registers the module provider on the next boot when
its host config file exists; do not register a module provider manually for this
activation flow. See [installation and upgrade](../../docs/INSTALLATION.md) for
config cache, worker restarts, upgrades, and deactivation.

## Technical behavior

Bundled module using Inventory Core. Publishing `inventory-library-config`
activates the module on the next boot. It owns only `invl_*` tables and imports
no Asset or other vertical code.

Apply LibraryPreset to an active stock Item, create one Core Serial per copy,
and receive each copy through Core. Core serial lifecycle remains unchanged:
Circulation and the active-allocation table are authoritative for Library loans.
Inventory movement, disposal, and serial relocation while allocated must be
controlled by the host application. Cross-vertical allocation is not coordinated.

CirculationService exposes:

- hold(number, itemId, warehouseId, patronType, patronId, expiresAt = null)
- fulfillNextHold(serialId)
- checkout(number, serialId, patronType, patronId, dueAt)
- checkin(loanId)
- renew(loanId, renewalNo, dueAt)
- expireReadyHolds()
- recordFine(loanId, number, amountMinor, currency, reason)

Patron references are external type/ID strings; no patron table is owned.
Waiting Holds create no reservation. Queue order is created_at then ID.
Ready Holds reserve one copy for ready_hours (default 48), capped at the Hold's
own expiry. Pickup by the same patron reuses the ready Reservation and records
the Hold link on the Circulation. Another patron cannot take that ready copy.
Check-in releases the loan Reservation and attempts to ready the next Hold.
Schedule expireReadyHolds() in the host to release expired ready allocations.
Waiting expiry is evaluated when that queue is processed.

All queue/copy operations lock Item then Serial, followed by domain rows in a
transaction. Serial is the active-allocation primary key; hold_id,
circulation_id and reservation_id also have unique constraints. Retries do not
create another loan, ready allocation, renewal or fine. Reservation quantities
are always one; on-hand and Stock Ledger remain unchanged during circulation.

Renewals extend due_at, keep an audit row, and are rejected while a live Hold
queue exists. Overdue is the derived is_overdue property on Circulation.
Fines are explicitly recorded positive integer minor units plus currency and
reason; there is no automatic tariff, payment processing or accounting posting.

Validation includes two independent PHP workers on a shared SQLite database:
competing checkout and check-in retry versus ready expiry. SQLite can reject
an overlapping writer as busy; rejected transactions leave no partial allocation.
These tests establish persisted invariants on SQLite, not MySQL/PostgreSQL
row-lock behavior. Run the same scenarios on the production database before
deployment.


## Contoh service

Ikuti [konvensi payload, hasil, dan error](../../docs/SALES_PURCHASING_INTEGRATION.md#konvensi-contoh-dan-error).
Contoh JSON model adalah proyeksi field terpilih; semua ID/nilai ilustratif dan
memerlukan data master yang sesuai. Contoh method terpisah bukan instruksi untuk
menjalankan seluruh mutasi berulang pada data produksi.

Prasyarat: item stock aktif sudah diberi LibraryPreset, satu serial per copy dan
saldo diterima melalui Core. Host menyediakan patronType/patronId dan otorisasi.
Tanggal dueAt/expiry harus sesuai waktu aplikasi; contoh memakai now() agar tetap
valid ketika dicoba di kemudian hari. Jangan bagikan serial dengan modul Asset.

```php
use ESolution\Inventory\Models\Item;
use ESolution\InventoryLibrary\Services\LibraryPreset;
use ESolution\InventoryLibrary\Services\CirculationService;

app(LibraryPreset::class)->apply(Item::findOrFail(1));
$service = app(CirculationService::class);
$hold = $service->hold('H-001', 1, 1, 'member', 'M-001');
$ready = $service->fulfillNextHold(10);
$loan = $service->checkout('L-001', 10, 'member', 'M-001', now()->addDays(7)->toDateTimeString());
$result = ['id' => $loan->id, 'loan_no' => $loan->loan_no,
    'serial_id' => (int) $loan->serial_id, 'checked_in_at' => $loan->checked_in_at];
```

Proyeksi pinjaman aktif (Circulation tidak menyediakan field status pinjaman tersendiri):

```json
{"id":1,"loan_no":"L-001","serial_id":10,"checked_in_at":null}
```

| Method | Input contoh / default | Nilai kembali dan efek |
|---|---|---|
| `LibraryPreset::apply` | Item model | Item, menyimpan preset; tidak menerima stok. |
| `hold` | number:string, itemId/warehouseId:int, patronType/patronId:string; expiresAt:?string=null | Hold status waiting; belum reservasi. |
| `fulfillNextHold` | serialId:int | Hold ready dengan reservation_id, atau null bila tak ada antrean/serial sedang dipinjam/stok tidak tersedia. |
| `checkout` | number:string, serialId:int, patronType/patronId/dueAt:string | Circulation; membuat/memakai reservasi ready hold milik patron yang sama. |
| `renew` | `$service->renew($loan->id, 'REN-001', now()->addDays(14)->toDateTimeString())` | Circulation dengan due_at baru + audit renewal. |
| `recordFine` | `$service->recordFine($loan->id, 'F-001', 5000, 'IDR', 'Terlambat')` | int ID fine, misalnya 1; amountMinor dalam satuan minor sesuai konvensi mata uang host, bukan perhitungan tarif. |
| `checkin` | `$service->checkin($loan->id)` | Circulation dengan checked_in_at terisi; melepas reservasi dan mencoba antrean berikutnya. |
| `expireReadyHolds` | `$service->expireReadyHolds()` | int jumlah kandidat ready hold kedaluwarsa yang diproses, misalnya 0; dapat menyiapkan hold berikutnya. |

Renew mensyaratkan pinjaman aktif, jatuh tempo diperpanjang ke masa depan, dan tidak
ada antrean hold aktif. Contoh error: `Renewal blocked by Hold queue.` Checkout
copy milik patron lain ditolak. Nomor hold/loan/renewal/fine dipakai untuk retry;
konflik field identitas diperiksa, tetapi retry checkout tidak memperbarui dueAt.
Checkin ulang tidak melepas dua kali. Jadwalkan expireReadyHolds pada host;
waiting hold diperiksa expiry saat antrean diproses. Semua operasi sirkulasi tidak
mengubah on-hand atau posting jurnal; recordFine tidak berarti pembayaran.
