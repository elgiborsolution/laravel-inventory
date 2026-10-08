# Panduan instalasi project baru dan project yang sudah berjalan

Panduan ini memasang Inventory Core versi 2.x dan sembilan modul opsional dari
satu repo, melalui distribusi online atau checkout lokal untuk pengembangan.
Jalankan perintah Composer dan Artisan di root aplikasi
Laravel tujuan (direktori yang memiliki `artisan`), bukan di direktori package.
Contoh perintah ditulis satu baris agar dapat digunakan di PowerShell atau Bash.

## 1. Pilih jalur instalasi

| Kondisi aplikasi | Jalur |
|---|---|
| Project baru, database kosong | Ikuti bagian 2–5, lalu verifikasi di bagian 8. |
| Project lama, belum punya inventory | Ikuti bagian 2–5 pada staging, petakan master aplikasi sesuai bagian 6. |
| Project lama, sudah punya saldo/transaksi stok | Ikuti bagian 6 sebelum mengaktifkan transaksi package. |
| Memakai schema Inventory versi lama | Audit schema dan buat migration upgrade khusus; baseline bukan upgrade otomatis. |

Package menyediakan service backend, bukan UI, endpoint CRUD, atau aplikasi ERP
lengkap. Instalasi semua modul tidak otomatis menghubungkan fitur ke aplikasi.
Controller, validasi, otorisasi, scheduler, dan integrasi bisnis tetap milik host.

Status implementasi dan batasan produksi tercatat di [release notes](RELEASE_NOTES.md).
Keberadaan tag GitHub tidak menutup blocker yang masih didokumentasikan.

## 2. Persiapan

1. Sediakan aplikasi Laravel, PHP CLI, Composer, dan database development/staging.
2. Periksa `php -v`, `composer --version`, dan `php artisan --version`.
3. Cocokkan versi dengan [matriks kompatibilitas](architecture/SUPPORTED_VERSIONS.md).
   Core dan sembilan modul mensyaratkan PHP `>=8.2` dan Illuminate 11?13; persyaratan PHP Laravel
   yang dipakai tetap berlaku. Laravel 10 ke bawah dan Laravel 14 ke atas tidak didukung.
   Pengecualian pada CI package bukan konfigurasi instalasi produksi.
4. Atur koneksi database pada `.env` host, kemudian jalankan `php artisan config:clear`.
5. Untuk aplikasi aktif, siapkan backup database, branch perubahan, dan staging.

Jika belum mempunyai project Laravel, buat project menggunakan versi Laravel yang
sesuai PHP Anda terlebih dahulu. Sebagai contoh untuk lingkungan PHP 8.2:

```bash
composer create-project laravel/laravel inventory-app "^12.0"
cd inventory-app
```

Perintah berikutnya mengasumsikan aplikasi sudah tersedia. Tidak perlu menjalankan
`composer install` di checkout package untuk menggunakannya sebagai dependency host;
dependency development package seperti Testbench digunakan untuk pengembangan package.

## 3. Instal sekali: Core beserta semua kode modul

Rilis yang memuat perubahan bundled modules memasang seluruh kode modul dalam
package `elgibor-solution/laravel-inventory`. Tidak perlu `composer require`
untuk WMS/Retail/modul lain, tidak perlu clone lokal, dan tidak ada konfigurasi
`modules.wms` atau switch modul lainnya. Provider Core aktif secara default;
provider modul hanya dimuat jika file `config/inventory-<modul>.php` sudah ada.

### Instal dari Packagist

Setelah maintainer menerbitkan rilis bundled dan metadata Packagist diperbarui:

```bash
composer require "elgibor-solution/laravel-inventory" --prefer-dist
php artisan vendor:publish --tag=inventory-config
php artisan config:clear
php artisan migrate
php artisan inventory:modules
```

### Alternatif path lokal untuk pengembangan

Daftarkan hanya root checkout, bukan `packages/*`, pada manifest host:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "D:/Project/inventori-package",
            "options": {
                "versions": {
                    "elgibor-solution/laravel-inventory": "2.0.x-dev"
                }
            }
        }
    ]
}
```

```bash
composer require "elgibor-solution/laravel-inventory:2.0.x-dev"
```

Override `2.0.x-dev` hanya untuk path lokal tersebut, bukan versi yang otomatis
tersedia di Packagist. Pertahankan repository lain milik host; hapus entri path
Inventory yang tidak lagi digunakan bila beralih ke online karena prioritas
canonical dapat menghalangi versi online.

### Upgrade dari instalasi modul terpisah

Simpan konfigurasi dan backup database. Hentikan worker/job terkait selama
peralihan. Hapus requirement Composer modul terpisah dari manifest host (dapat
menggunakan `composer remove --no-update` dengan nama modul yang memang ada),
lalu require/update Core ke rilis bundled. Tinjau perubahan lock file sebelum
deployment. Manifest Core menggunakan `replace` untuk sembilan nama package
lama agar dependency tersebut dipenuhi oleh bundle tanpa duplikasi class.

File konfigurasi modul yang sudah ada akan **langsung mengaktifkan modul** pada
boot berikutnya. Hapus registrasi provider modul manual agar aktivasi benar-benar
dikendalikan file. Jangan menghapus tabel lama: migration mempertahankan nama
sehingga migration yang sudah tercatat tidak diulang. Uji perpindahan pada staging.

## 4. Konfigurasi dan migration untuk instalasi baru

Provider mendukung Laravel auto-discovery. Jika host menonaktifkannya melalui
`extra.laravel.dont-discover`, daftarkan `ESolution\Inventory\InventoryServiceProvider` secara manual pada
tempat registrasi provider versi Laravel host. Core kemudian mendaftarkan provider
modul berdasarkan file konfigurasi host; tidak perlu registrasi manual per modul.

Publish Core terlebih dahulu. Untuk mengaktifkan WMS, jalankan
perintah berikut; proses `migrate` berikutnya memuat provider WMS:

```bash
php artisan config:clear
php artisan vendor:publish --tag=inventory-wms-config
php artisan migrate
php artisan inventory:modules
```

Publish tidak mengaktifkan ulang aplikasi dalam proses yang sama. Karena itu
migration dijalankan sebagai perintah Artisan berikutnya. Tidak ada download
ulang dan tidak ada flag modul. Publish hanya modul yang ingin diaktifkan.
**Daftar berikut mengaktifkan seluruh modul**, bukan sekadar menyalin template:

```bash
php artisan vendor:publish --tag=inventory-config
php artisan vendor:publish --tag=inventory-retail-config
php artisan vendor:publish --tag=inventory-wms-config
php artisan vendor:publish --tag=inventory-manufacturing-config
php artisan vendor:publish --tag=inventory-healthcare-config
php artisan vendor:publish --tag=inventory-food-config
php artisan vendor:publish --tag=inventory-asset-config
php artisan vendor:publish --tag=inventory-project-config
php artisan vendor:publish --tag=inventory-automotive-config
php artisan vendor:publish --tag=inventory-library-config
```

Project memiliki file konfigurasi minimal sebagai penanda aktivasi. Jangan gunakan `--force`
untuk menimpa konfigurasi aplikasi yang sudah disesuaikan; gabungkan perubahan
secara manual setelah membandingkan dengan konfigurasi package.

Pada `config/inventory.php`, tentukan struktur organisasi, storage, costing,
kebijakan stok negatif, dan idempotency sebelum membuat transaksi. Validator
saat ini mewajibkan level organisasi warehouse serta storage warehouse dan rack.
Scope costing dapat `warehouse` atau `rack`; scope rack memerlukan location ID.
Untuk mengikuti perhitungan rata-rata bergerak, pilih
`inventory.costing.default_method = moving_average` atau `inv_items.costing_method`
per item. Default package tetap FIFO untuk kompatibilitas. Jangan mengganti metode
pada stok aktif tanpa rekonsiliasi nilai dan pengujian; histori tidak dihitung ulang.
Moving Average menolak stok kurang meskipun kebijakan FIFO mengizinkan stok negatif.
Mulai dengan Accounting nonaktif. Approval bergantung pada keberadaan package
eksternal, bukan sebuah switch `approval.enabled` pada Inventory.

```bash
php artisan config:clear
php artisan inventory:validate-config
php artisan migrate:status
php artisan migrate --pretend
php artisan migrate
```

Migration Core dan modul aktif dimuat otomatis; tidak perlu publish migration.
Automotive tidak memiliki migration tambahan. `migrate` menjalankan semua
migration host yang pending, termasuk milik package lain. Periksa daftar dan SQL
sebelum menjalankannya. Jangan mengganti nama migration package lalu menjalankan
salinannya sebagai migration baru.

## 5. Master data dan pengaktifan fitur

Setelah instalasi, gunakan [panduan input dan hasil service](SALES_PURCHASING_INTEGRATION.md#panduan-input-dan-hasil-service)
untuk parameter wajib/opsional, contoh DTO/payload, proyeksi hasil, dampak transaksi,
serta aturan error/retry. Contoh service tiap modul tersedia pada README yang
ditautkan di tabel berikut.

Setelah migration, buat master melalui seeder/service host: organisasi dan gudang,
kategori barang, satuan dasar, barang, serta lokasi penyimpanan sesuai kebutuhan.
Gunakan ID master `inv_*` pada DTO Core. ID produk/gudang aplikasi lama tidak
otomatis sama dengan ID package. Untuk barang tracked, siapkan batch, serial,
expiry, dan sertifikat sesuai aturan item.

Semua perubahan stok dilakukan melalui `Inventory::post(DocumentData)` atau
service modul yang memanggil Core. Jangan mengisi ledger, cost layer, atau saldo
secara langsung. Contoh penerimaan tersedia pada [README](../README.md#posting-example);
reservasi dan fulfillment ada di [integrasi Sales/Purchasing](SALES_PURCHASING_INTEGRATION.md).

| Modul (kode aktivasi) | Langkah setelah instalasi | Dokumentasi |
|---|---|---|
| Core | Hubungkan penerimaan, pengeluaran, reservasi, availability, dan kartu stok ke service host. | [API dan schema](SOURCE_REFERENCE.md) |
| Retail (`retail`) | Buat product family/varian; aktifkan `inventory-retail.consignment.enabled` jika diperlukan, lalu atur terms supplier. POS/e-commerce memakai posting/reservasi Core. | [Retail](../packages/retail/README.md) |
| WMS (`wms`) | Atur strategi put-away/picking dan lokasi; hubungkan task, wave, LPN, serta penyelesaian replenishment ke proses host. Saran lokasi sendiri tidak mengubah saldo. | [WMS](../packages/wms/README.md) |
| Manufacturing (`manufacturing`) | Buat dan aktifkan versi BOM, buat Production Order, lalu gunakan `ProductionOrderService::complete()`. | [Manufacturing](../packages/manufacturing/README.md) |
| Healthcare (`healthcare`) | Terapkan `HealthcarePreset` pada item terkait; siapkan batch, expiry, COA, serta alur recall. | [Healthcare](../packages/healthcare/README.md) |
| Food (`food`) | Buat versi Recipe; gunakan `RecipeBatchService` untuk MTS atau metadata recipe pada alur MTO. Terapkan `FoodPreset` hanya pada item yang memerlukan aturan tersebut. | [Food](../packages/food/README.md) |
| Asset (`asset`) | Terapkan `AssetPreset`, siapkan serial dan alur checkout/check-in. Hubungkan notifier overdue. | [Asset](../packages/asset/README.md) |
| Project (`project`) | Petakan referensi proyek dan organisasi site/gudang; buat allocation, replenishment, reallocation, dan material draw. | [Project](../packages/project/README.md) |
| Automotive (`automotive`) | Hubungkan work order/kendaraan host; gunakan `WorkOrderParts::issue`. Terapkan `AutomotivePreset` untuk aturan serial/compliance yang dibutuhkan. | [Automotive](../packages/automotive/README.md) |
| Library (`library`) | Terapkan `LibraryPreset`, satu serial per copy, terima stok melalui Core, lalu hubungkan hold/checkout/check-in/renewal pada `CirculationService`. | [Library](../packages/library/README.md) |

Preset tidak otomatis diterapkan pada seluruh barang saat package dipasang.
Jangan memakai Asset dan Library untuk mengalokasikan serial yang sama karena
proteksi alokasi lintas kedua modul belum terkoordinasi.

Scheduler dikonfigurasi oleh host sesuai kebutuhan operasional:

- Retail: `inventory-retail:consignment:settle --through=YYYY-MM-DD` untuk
  menandai kewajiban konsinyasi settled; bukan pembayaran/jurnal otomatis.
- WMS: `inventory-wms:replenish --warehouse=ID` membuat pekerjaan internal;
  penyelesaiannya perlu dihubungkan ke transfer Core oleh host.
- Asset: `inventory-assets:detect-overdue` memanggil notifier yang dikonfigurasi.
- Library: jadwalkan pemanggilan `CirculationService::expireReadyHolds()` melalui
  job/command host, bukan nama command Artisan yang belum disediakan.

Tentukan frekuensi, akses tenant, retry, dan pencegahan overlap pada host.

### 5.1. Kegunaan, fungsi, dan fitur sembilan modul

Semua modul berikut sudah termasuk dalam bundle Core. Pilih berdasarkan proses
bisnis yang diperlukan, lalu aktifkan dengan publish konfigurasi seperti bagian 4.
Core tetap menangani stok, ledger, costing, dan reservasi; modul menambahkan
proses khusus tanpa menjadi aplikasi bisnis lengkap. Contoh berikut menggambarkan
penggunaan setelah host menghubungkan master data, service, dan UI/API.

#### Retail

**Kegunaan:** Toko dengan varian produk dan barang titipan supplier (konsinyasi).

**Fungsi dan fitur:** Membentuk kombinasi ukuran/warna sebagai Core Item terpisah; mengatur terms konsinyasi per barang/lokasi; mencatat kewajiban settlement dari barang terjual. POS memakai posting Core, sedangkan e-commerce dapat memakai reservasi sebelum fulfillment.

**Contoh penggunaan:** Toko pakaian membuat SKU untuk setiap ukuran dan warna, lalu melacak penjualan barang titipan.

**Batas dan integrasi host:** Harga, diskon, kasir, storefront, pembayaran supplier, dan jurnal settlement tetap ditangani aplikasi host.

Dokumentasi teknis: [Retail](../packages/retail/README.md).

#### WMS

**Kegunaan:** Gudang yang membutuhkan pengaturan pekerjaan penyimpanan dan pengambilan barang.

**Fungsi dan fitur:** Strategi put-away (penempatan), picking FIFO/FEFO, task, wave (kelompok tugas picking), LPN (identitas container), cross-docking, dan pekerjaan replenishment (pengisian ulang lokasi).

**Contoh penggunaan:** Barang diterima, petugas memperoleh tugas penempatan, kemudian mengambil barang berdasarkan wave untuk pengiriman.

**Batas dan integrasi host:** Saran lokasi dan pekerjaan replenishment tidak otomatis mengubah saldo. Host menghubungkan penyelesaian pekerjaan dengan posting/transfer Core; integrasi transportasi juga milik host.

Dokumentasi teknis: [WMS](../packages/wms/README.md).

#### Manufacturing

**Kegunaan:** Produksi atau perakitan barang berdasarkan BOM (daftar kebutuhan bahan).

**Fungsi dan fitur:** BOM berversi yang tidak dapat diubah setelah aktivasi; Production Order; konsumsi bahan dan penerimaan hasil dalam satu transaksi; biaya aktual bahan; scrap, selisih pemakaian/hasil, dan produksi bertahap melalui WIP.

**Contoh penggunaan:** Merakit produk dari beberapa komponen, lalu menghitung biaya hasil berdasarkan biaya komponen yang benar-benar dikeluarkan.

**Batas dan integrasi host:** Referensi pesanan bisnis berasal dari host. Penyelesaian produksi saat ini mensyaratkan accounting Core/modul nonaktif dan NullAccountingBridge.

Dokumentasi teknis: [Manufacturing](../packages/manufacturing/README.md).

#### Healthcare

**Kegunaan:** Persediaan farmasi atau bahan medis yang memerlukan pengawasan batch dan kedaluwarsa.

**Fungsi dan fitur:** Preset batch/expiry, FEFO (kedaluwarsa terdekat dikeluarkan dahulu), COA (sertifikat analisis) saat pengeluaran, penerimaan kedaluwarsa dengan disposisi terkontrol, recall, dan penelusuran dokumen pengeluaran batch.

**Contoh penggunaan:** Menarik batch obat tertentu dan menelusuri dokumen yang pernah mengeluarkan batch tersebut.

**Batas dan integrasi host:** FEFO dimiliki Core dan tidak membutuhkan WMS. Preset harus diterapkan pada item terkait; modul tidak menyediakan aplikasi klinik atau rekam medis.

Dokumentasi teknis: [Healthcare](../packages/healthcare/README.md).

#### Food

**Kegunaan:** Produksi makanan berdasarkan resep untuk stok atau pesanan.

**Fungsi dan fitur:** Recipe berversi, RecipeBatch dengan konsumsi bahan/penerimaan hasil atomik, biaya aktual bahan, produksi MTS (untuk stok) dan MTO (sesuai pesanan), serta preset batch dan persyaratan sertifikat halal.

**Contoh penggunaan:** Memproduksi satu batch makanan dari resep dan membukukan biaya bahan ke hasil produksi.

**Batas dan integrasi host:** FEFO dapat diatur melalui Core tanpa Healthcare/WMS. Penyelesaian RecipeBatch saat ini memerlukan accounting Core/Food nonaktif; integrasi pesanan dan UI tetap milik host.

Dokumentasi teknis: [Food](../packages/food/README.md).

#### Asset

**Kegunaan:** Peminjaman aset perusahaan yang perlu dilacak per serial.

**Fungsi dan fitur:** Checkout/check-in, reservasi aset, pencegahan alokasi aktif ganda, tanggal jatuh tempo, dan notifier keterlambatan yang dapat diganti.

**Contoh penggunaan:** Meminjamkan laptop atau alat kerja kepada pegawai lalu mencatat pengembaliannya.

**Batas dan integrasi host:** Checkout/check-in tidak mengubah stok on-hand. Status peminjaman mengikuti data checkout/alokasi, bukan status serial on_loan. Host mengatur penerima, UI, dan jadwal notifikasi; jangan alokasikan serial yang sama melalui Asset dan Library.

Dokumentasi teknis: [Asset](../packages/asset/README.md).

#### Project

**Kegunaan:** Penyediaan dan pengambilan material untuk proyek atau site.

**Fungsi dan fitur:** Alokasi berbasis reservasi, penambahan alokasi, pemindahan alokasi secara atomik, pengambilan material sebagian, serta laporan alokasi dan konsumsi.

**Contoh penggunaan:** Mereservasi material untuk proyek konstruksi lalu mengeluarkannya bertahap sesuai kebutuhan lapangan.

**Batas dan integrasi host:** Identitas proyek berasal dari host. Pemindahan alokasi reservasi bukan perpindahan fisik barang; perubahan stok tetap melalui Core. Modul tidak menyediakan manajemen jadwal atau anggaran proyek.

Dokumentasi teknis: [Project](../packages/project/README.md).

#### Automotive

**Kegunaan:** Pencatatan pemakaian sparepart untuk work order dan kendaraan.

**Fungsi dan fitur:** WorkOrderParts::issue untuk pengeluaran sparepart melalui Core, laporan pemakaian per work order/kendaraan/barang/serial, serta preset serial dan sertifikat compliance.

**Contoh penggunaan:** Mengeluarkan sparepart untuk servis kendaraan dan melaporkan kuantitas serta biaya pemakaiannya.

**Batas dan integrasi host:** Master kendaraan dan work order dimiliki host; tidak ada migration tambahan. Pengeluaran Automotive ditolak ketika accounting terkait aktif atau bridge bukan NullAccountingBridge karena service code belum terverifikasi.

Dokumentasi teknis: [Automotive](../packages/automotive/README.md).

#### Library

**Kegunaan:** Sirkulasi peminjaman buku atau koleksi per eksemplar.

**Fungsi dan fitur:** Satu serial per copy, antrean hold, reservasi copy yang siap diambil, checkout/check-in, perpanjangan, kedaluwarsa ready hold, status terlambat, dan pencatatan denda.

**Contoh penggunaan:** Anggota memesan buku, mengambil copy yang tersedia, lalu mengembalikan atau memperpanjang pinjaman.

**Batas dan integrasi host:** Anggota berasal dari host. Sirkulasi tidak mengubah on-hand; tarif denda otomatis, pembayaran, dan jurnal tidak disediakan. Host menjadwalkan expireReadyHolds(); jangan alokasikan serial yang sama melalui Asset dan Library.

Dokumentasi teknis: [Library](../packages/library/README.md).

## 6. Project lama: integrasi dan perpindahan data

Pemasangan Composer sama dengan bagian 3. Perbedaannya adalah penanganan schema,
data, dan alur transaksi yang sudah berjalan. Baseline package tidak menyediakan
importer data lama atau migration upgrade dari schema Inventory generasi sebelumnya.

1. Jalankan di salinan database/staging terlebih dahulu. Catat versi package lama,
   daftar migration, tabel `inv_*`/prefix modul, konfigurasi, serta saldo awal.
   Bila tabel sudah ada, bandingkan schema sebelum migration; jangan mengatasi
   benturan dengan menghapus tabel atau sekadar menandai migration sudah berjalan.
2. Instal Core/modul yang diperlukan, gabungkan konfigurasi, dan tinjau migration
   seperti bagian 3–4. Jangan menjalankan `migrate:fresh` atau `migrate:reset`
   pada database aplikasi aktif.
3. Buat pemetaan ID produk, UOM, gudang/organisasi, lokasi, batch/serial, dan
   referensi pihak dari aplikasi lama ke master package. Simpan pemetaan agar
   import dapat dilacak dan diulang tanpa duplikasi.
4. Jika belum ada inventory, mulai transaksi setelah master dan service host siap.
   Jika sudah ada inventory, tentukan tanggal cutover dan hentikan sementara
   penulisan stok ketika mengambil saldo final serta menjalankan perpindahan.
5. Buat importer khusus untuk saldo kuantitas dan nilai persediaan melalui posting
   Core, misalnya `positive_adjustment` untuk saldo awal positif yang telah
   direkonsiliasi. Tetapkan `externalId` unik dan stabil untuk retry. Saldo negatif,
   tracking, reservasi aktif, serta dokumen belum selesai memerlukan keputusan
   migrasi tersendiri; jangan menganggap satu adjustment mencakup semuanya.
6. Untuk FIFO, putuskan apakah lapisan biaya lama harus direkonstruksi.
   Memasukkan saldo agregat dengan satu biaya rata-rata dapat mengubah HPP
   pengeluaran berikutnya. Pilih import sejarah atau saldo cutover; jangan
   memposting keduanya sehingga stok terhitung dua kali.
7. Alihkan seluruh jalur penerimaan, penjualan, retur, transfer, dan adjustment ke
   service Core/modul. Tetapkan satu sumber saldo resmi dan cegah penulisan ganda.
   Simpan histori lama untuk kebutuhan pelacakan sesuai kebijakan aplikasi.
8. Rekonsiliasi per barang/gudang/lokasi dan tracking: kuantitas, nilai, reservasi,
   serta saldo tersedia. Buka kembali transaksi setelah hasilnya sesuai.

Stock opname untuk barang stock tanpa tracking kini memakai `qty` sebagai hasil
hitung fisik (boleh nol), bukan kuantitas adjustment. Saat posting/resume approval,
Core mengunci item, menghitung fisik dikurangi saldo sistem saat itu, lalu memposting
hanya selisih. Jangan memposting adjustment tambahan untuk selisih yang sama.
Host harus menjaga hasil hitung tetap valid selama menunggu approval (misalnya
menghentikan transaksi pada lokasi yang dihitung); package belum menyediakan freeze
sesi opname. Baca [perhitungan bisnis](SALES_PURCHASING_INTEGRATION.md#business-calculations)
untuk unitCost, pricing, kartu stok, dan batas dukungan.

Siapkan rollback operasional di staging. Menghapus dependency Composer tidak
mengembalikan data hasil import. Jangan memakai rollback migration global untuk
mencopot satu modul karena batch dapat berisi migration aplikasi lain.

## 7. Accounting dan Approval: integrasi terpisah

Kedua package berikut tidak berada dalam direktori `packages/*` Inventory.
Sediakan repository/distribusi dan versi kompatibel sesuai dokumentasi masing-masing
sebelum menjalankan perintah berikut. Inventory tidak menetapkan matriks versi
eksternal terverifikasi; keberhasilan Composer saja belum membuktikan integrasi.

### Accounting

```bash
composer require elgibor-solution/laravel-accounting
```

Ikuti instalasi, konfigurasi, dan migration package Accounting terlebih dahulu.
Atur mapping service/account, koneksi, dan tenant; kemudian aktifkan
`inventory.accounting.enabled` dan jalankan:

```bash
php artisan inventory:accounting:validate
```

**Jangan mengaktifkan semua fitur accounting sekaligus:** Manufacturing, Food,
dan Automotive masih menolak operasi terkait ketika accounting aktif karena
service code yang diperlukan belum terverifikasi. Agar alur tersebut berjalan,
Core dan konfigurasi accounting modul terkait harus tetap nonaktif dengan
Null bridge. Lihat [Accounting Bridge](ACCOUNTING_BRIDGE.md).

### Approval

```bash
composer require e-solution/laravel-approval-flow
```

Keberadaan kelas WorkflowEngine eksternal mengaktifkan bridge. Ikuti instalasi
package tersebut, sediakan IdentityResolver, workflow/rule aktif, keputusan
service auth untuk HTTP/queue/console, dan
`approval-flow.default_status_field = approval_status`. Atur rejection map
Inventory, lalu jalankan:

```bash
php artisan inventory:approval:validate
```

Uji submit, approved/resume, rejected, cancelled, dan callback berulang. Detail
tersedia pada [Approval Bridge](APPROVAL_BRIDGE.md). Transfer/reversal Core saat
ini memiliki batasan tracking dan bridge; lihat [Transfer/Reversal](TRANSFER_REVERSAL.md).

## 8. Verifikasi dan deployment

Pada host development/staging, periksa instalasi:

```bash
composer show "elgibor-solution/laravel-inventory*"
php artisan migrate:status
php artisan inventory:validate-config
php artisan config:cache
php artisan inventory:validate-config
```

Jalankan juga validator bridge yang diaktifkan. Dengan config cache aktif, uji
penerimaan 10 unit dan pengeluaran 3 unit pada item uji yang baru; saldo akhir
harus 7. Kirim ulang transaksi dengan external ID yang sama dan pastikan tidak
menambah pergerakan. Periksa kartu stok, nilai persediaan, reservasi, serta
satu alur bisnis setiap modul yang dipakai. Setelah uji development:

```bash
php artisan config:clear
```

`inventory:validate-config` memeriksa konfigurasi tertentu, bukan seluruh kesiapan
database, keamanan, workflow, atau integrasi. Uji otorisasi organisasi/gudang pada
host, transaksi gagal/rollback, dan konkurensi pada engine database produksi.

Commit manifest dan lock file host setelah dependency selesai dipilih. Deployment
memakai `composer install` dari lock yang ditinjau, bukan memilih versi baru dengan
`composer update`. Path repository membutuhkan checkout sumber pada path yang
sesuai di server/build environment. Jika menggunakan symlink, sumber juga harus
tersedia saat runtime. Pin checkout sumber ke commit yang sama dengan hasil uji;
lock file saja tidak membekukan isi direktori path yang masih dapat diedit.

Untuk instalasi online bagian 3A, server tidak memerlukan checkout source lokal:
Composer mengunduh ZIP dari URL dist yang tercatat di lock file. Pertahankan
release assets lama agar instalasi dari lock tetap berhasil. Ikuti urutan deployment host untuk migration,
cache konfigurasi, restart worker, serta aktivasi scheduler setelah staging lolos.

## 9. Status modul dan penonaktifan

```bash
php artisan inventory:modules
```

Command menampilkan seluruh sembilan modul dengan kolom:

| Kolom | Arti |
|---|---|
| Code available | Class provider tersedia melalui autoload. |
| Config published | File konfigurasi modul ada pada direktori config host. |
| Active | Provider sudah terdaftar pada proses aplikasi saat ini. |
| Migrations | `Complete`, jumlah `pending`, `Not required` untuk Automotive, atau `Unknown (database)` bila koneksi gagal. |

Tabel yang sudah ada tidak otomatis berarti migration tercatat. Command membaca
repository migration Laravel pada koneksi default host; jika menjalankan migration
pada koneksi lain, sesuaikan koneksi host saat memeriksa. Status ini bukan audit
kelengkapan schema atau integrasi bisnis. File konfigurasi dan status Active dapat
berbeda bila provider didaftarkan manual atau perubahan terjadi pada proses lama.

Untuk menonaktifkan modul, hentikan job terkait, hapus/pindahkan file
`config/inventory-<modul>.php` keluar direktori config, bersihkan cache, lalu
restart worker/server berumur panjang. **Data dan tabel tidak dihapus.** Jangan
rollback global untuk mencopot satu modul. Menghapus konfigurasi menghentikan
registrasi provider pada boot berikutnya, bukan menghapus kode atau membatasi
akses langsung ke class modul oleh kode host.

Jika memakai cache konfigurasi, setelah aktivasi/penonaktifan bangun kembali:

```bash
php artisan config:clear
php artisan config:cache
```

Restart queue workers/Octane sesuai deployment aplikasi. Selalu clear cache sebelum
publish agar nilai konfigurasi modul baru tersedia pada proses berikutnya.

| Masalah | Tindakan |
|---|---|
| Tag publish modul/command status tidak ada | Periksa apakah versi Core sudah memuat bundle baru. |
| Katalog online 404 | Aktifkan deployment atau hapus entri katalog dan gunakan rilis Packagist yang tersedia. |
| Modul tetap tidak aktif | Periksa file config pada host, cache, registrasi manual, dan proses worker lama. |
| Migration pending | Jalankan `migrate` setelah publish, dengan meninjau migration lain yang pending. |
| Composer menolak versi Laravel host | Gunakan Laravel 11, 12, atau 13 dengan versi PHP yang sesuai. |
| Accounting menolak operasi modul | Periksa batasan Manufacturing/Food/Automotive di bagian 7. |

## 10. Maintainer: rilis bundle dari satu repo

Core memiliki runtime autoload semua namespace modul dan `replace` untuk nama
package lama. Direktori `packages/*` beserta manifestnya dipertahankan dalam
bundle sebagai katalog internal. Jangan menghapus manifest tersebut; Core
menggunakannya untuk menemukan provider dan konfigurasi modul.

Distribusi melalui Packagist/VCS root mengunduh repository yang sudah membawa
modul. Pastikan tag baru memuat perubahan autoload, provider, konfigurasi Project,
dan command status, kemudian perbarui metadata Packagist.

Untuk distribusi GitHub Releases/Pages alternatif:

1. Commit/push perubahan dan pastikan workflow juga tersedia pada default branch.
2. Pilih **Settings > Pages > Source > GitHub Actions**, dan izinkan deployment
   tag pada environment `github-pages`.
3. Selesaikan CI dan `php tools/release-preflight.php`. Blocker yang masih terbuka
   tetap menghentikan distribusi; patch ini tidak menghapus gate.
4. Buat tag/rilis baru (misalnya `v2.0.2` hanya jika belum digunakan).
5. Workflow [Distribute Composer packages](../.github/workflows/distribute.yml)
   membuat **satu ZIP Core lengkap dengan seluruh modul** dan metadata
   `inventory-packages.json`, lalu menggabungkan katalog ke Pages.
6. Jika CI belum selesai ketika rilis dibuat, retry manual dengan tag yang sama
   setelah CI berhasil. Workflow tidak menimpa asset berbeda pada nama yang sama.
7. Periksa hasil ZIP: autoload modul, manifest `packages/*/composer.json`, config,
   migration, dan source semua modul harus tersedia. Uji install host disposable,
   aktivasi WMS tiga perintah, config cache, dan status modul sebelum pengumuman.

Katalog mempertahankan metadata release lama, termasuk artifact terpisah versi
lama bila pernah diterbitkan. Jangan menghapus asset lama yang masih digunakan
lock file. Gunakan tag baru untuk bundle agar tidak mengganti isi artifact versi
lama. Lokasi kode modul pada host baru berada di
`vendor/elgibor-solution/laravel-inventory/packages/`.

Pemeriksaan lokal, tanpa memasang dependency:

```bash
python -m unittest discover -s tools/tests -p test_distribution.py
```

Untuk membangun dari tag lokal yang **sudah memuat bundle baru**, sesuaikan tag:

```bash
python tools/distribute.py build --tag v2.0.2 --repository elgiborsolution/laravel-inventory
```

Output di `build/distribution`; builder membaca tag, bukan working tree. Perintah
build tidak publish. Subcommand `publish` melakukan upload nyata dan digunakan
oleh workflow setelah gate lolos.

Referensi: [runbook](ECOSYSTEM_RELEASE.md), [API/schema](SOURCE_REFERENCE.md),
[release notes](RELEASE_NOTES.md).
