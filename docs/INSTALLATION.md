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
   Core mendeklarasikan PHP `^8.1` dan Illuminate 9–13; persyaratan PHP Laravel
   yang dipakai tetap berlaku. Laravel 9 dapat diblokir advisory keamanan.
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

## 3. Pilih satu sumber instalasi

### A. Tanpa clone: Composer repository online

Source tetap berada dalam satu repo. Workflow `Distribute Composer packages`
membuat ZIP terpisah untuk Core dan sembilan modul di GitHub Releases, lalu
menerbitkan `packages.json` kumulatif ke GitHub Pages. Modul dipasang ke
`vendor/elgibor-solution/`, bukan ke folder `packages/` aplikasi pengguna.

**Prasyarat:** maintainer harus menyelesaikan bagian 10 dan berhasil menjalankan
workflow distribusi. URL di bawah adalah URL yang diharapkan untuk repo tersebut,
bukan pernyataan bahwa layanan sudah aktif. Periksa bahwa
`https://elgiborsolution.github.io/laravel-inventory/packages.json` dapat diakses
dan berisi versi modul sebelum menjalankan Composer. Untuk fork/custom domain,
gunakan URL Pages hasil deployment Anda.

Jika sebelumnya memakai contoh repository lokal, hapus entri path Core/modul
tersebut dari `composer.json` host. Contoh untuk nama entri pada percakapan migrasi:

```bash
composer config --unset repositories.local-inventory
composer config --unset repositories.inventory-wms-local
```

Jalankan hanya untuk entri yang memang ada; entri array atau nama lain perlu
disesuaikan. Repository path yang canonical dapat menghalangi versi online.
Daftarkan repository distribusi, dengan tetap mempertahankan repository lain:

```bash
composer config repositories.inventory composer https://elgiborsolution.github.io/laravel-inventory
```

Pilih salah satu perintah sesuai fitur yang diperlukan (setelah tersedia rilis
stabil 2.x dalam katalog):

```bash
# Core saja
composer require "elgibor-solution/laravel-inventory:^2.0" --prefer-dist

# Core dan WMS; Core dipasang otomatis sebagai dependency
composer require "elgibor-solution/laravel-inventory:^2.0" "elgibor-solution/laravel-inventory-wms:^2.0" --prefer-dist

# Core, Retail, dan WMS
composer require "elgibor-solution/laravel-inventory:^2.0" "elgibor-solution/laravel-inventory-retail:^2.0" "elgibor-solution/laravel-inventory-wms:^2.0" --prefer-dist
```

Jika host masih mengunci Core sebagai `dev-main`, sertakan constraint Core `^2.0`
seperti contoh saat berpindah ke rilis ZIP. `-W` hanya diperlukan bila ada konflik
dependency terkunci yang memang perlu diperbarui; bukan solusi package tak ditemukan.

Untuk seluruh modul:

```bash
composer require "elgibor-solution/laravel-inventory:^2.0" "elgibor-solution/laravel-inventory-retail:^2.0" "elgibor-solution/laravel-inventory-wms:^2.0" "elgibor-solution/laravel-inventory-manufacturing:^2.0" "elgibor-solution/laravel-inventory-healthcare:^2.0" "elgibor-solution/laravel-inventory-food:^2.0" "elgibor-solution/laravel-inventory-asset:^2.0" "elgibor-solution/laravel-inventory-project:^2.0" "elgibor-solution/laravel-inventory-automotive:^2.0" "elgibor-solution/laravel-inventory-library:^2.0" --prefer-dist
```

`@dev` tidak diperlukan untuk rilis stabil. Jika hanya tersedia prerelease,
gunakan versi prerelease yang benar-benar tercantum di katalog secara eksplisit
pada Core dan modul pilihan. Setelah Composer berhasil, lanjutkan ke bagian 4.
Mengganti constraint tidak dapat menemukan modul yang belum didistribusikan.

### B. Checkout lokal: untuk pengembangan package

Repository: `https://github.com/elgiborsolution/laravel-inventory.git`.
Jika checkout belum ada, clone di lokasi yang tersedia untuk host, misalnya:

```bash
git clone https://github.com/elgiborsolution/laravel-inventory.git D:/Project/inventori-package
```

Gunakan commit/rilis yang sudah memuat dependency Core `^2.0` pada seluruh
`packages/*/composer.json`. Tag `2.0.0` awal masih memakai `^1.0` pada modul;
checkout tag itu saja tidak menyelesaikan konflik versi. Jangan mengubah tag lama
atau menyamarkan Core 2.x sebagai versi 1.x untuk melewati konflik tersebut.

Composer VCS mengenali package pada root repo. Tag GitHub tidak otomatis
menerbitkan subdirektori sebagai package terpisah. Untuk memakai modul dari
checkout yang sama, gabungkan bagian berikut ke `composer.json` host, tanpa
menghapus repository yang sudah ada:

```json
"repositories": [
    {
        "type": "path",
        "url": "D:/Project/inventori-package",
        "options": {
            "versions": {
                "elgibor-solution/laravel-inventory": "2.0.x-dev"
            }
        }
    },
    {
        "type": "path",
        "url": "D:/Project/inventori-package/packages/*"
    }
]
```

Ini potongan konfigurasi, bukan pengganti seluruh file. Sesuaikan path untuk
mesin Anda; path relatif dihitung dari direktori host. `2.0.x-dev` memberi
versi development yang memenuhi `^2.0`, bukan label rilis stabil.
Wildcard hanya mendaftarkan modul yang tersedia; tidak memasang semuanya.
Lihat juga [Composer path repositories](https://getcomposer.org/doc/05-repositories.md#path).

#### Pasang Core saja dari path lokal

```bash
composer require "elgibor-solution/laravel-inventory:2.0.x-dev"
```

#### Pasang Core dan modul pilihan dari path lokal

Contoh Retail dan WMS:

```bash
composer require "elgibor-solution/laravel-inventory:2.0.x-dev" "elgibor-solution/laravel-inventory-retail:@dev" "elgibor-solution/laravel-inventory-wms:@dev"
```

#### Pasang Core dan seluruh sembilan modul dari path lokal

```bash
composer require "elgibor-solution/laravel-inventory:2.0.x-dev" "elgibor-solution/laravel-inventory-retail:@dev" "elgibor-solution/laravel-inventory-wms:@dev" "elgibor-solution/laravel-inventory-manufacturing:@dev" "elgibor-solution/laravel-inventory-healthcare:@dev" "elgibor-solution/laravel-inventory-food:@dev" "elgibor-solution/laravel-inventory-asset:@dev" "elgibor-solution/laravel-inventory-project:@dev" "elgibor-solution/laravel-inventory-automotive:@dev" "elgibor-solution/laravel-inventory-library:@dev"
```

Perintah ini tidak memasang integrasi Accounting atau Approval eksternal.
Memasang seluruh katalog juga tidak berarti semua kombinasi proses bisnis sudah
terverifikasi; perhatikan batasan pada bagian 5 dan 7.

## 4. Konfigurasi dan migration untuk instalasi baru

Provider mendukung Laravel auto-discovery. Jika host menonaktifkannya melalui
`extra.laravel.dont-discover`, daftarkan provider secara manual pada tempat
registrasi provider versi Laravel host; daftarkan Core sebelum modul.
Nama provider tercantum di `extra.laravel.providers` pada manifest masing-masing.

Publish Core dan hanya konfigurasi modul yang sudah dipasang. Jika memasang semua:

```bash
php artisan vendor:publish --tag=inventory-config
php artisan vendor:publish --tag=inventory-retail-config
php artisan vendor:publish --tag=inventory-wms-config
php artisan vendor:publish --tag=inventory-manufacturing-config
php artisan vendor:publish --tag=inventory-healthcare-config
php artisan vendor:publish --tag=inventory-food-config
php artisan vendor:publish --tag=inventory-asset-config
php artisan vendor:publish --tag=inventory-automotive-config
php artisan vendor:publish --tag=inventory-library-config
```

Project tidak memiliki konfigurasi untuk dipublish. Jangan gunakan `--force`
untuk menimpa konfigurasi aplikasi yang sudah disesuaikan; gabungkan perubahan
secara manual setelah membandingkan dengan konfigurasi package.

Pada `config/inventory.php`, tentukan struktur organisasi, storage, costing,
kebijakan stok negatif, dan idempotency sebelum membuat transaksi. Validator
saat ini mewajibkan level organisasi warehouse serta storage warehouse dan rack.
Scope costing dapat `warehouse` atau `rack`; scope rack memerlukan location ID.
Mulai dengan Accounting nonaktif. Approval bergantung pada keberadaan package
eksternal, bukan sebuah switch `approval.enabled` pada Inventory.

```bash
php artisan config:clear
php artisan inventory:validate-config
php artisan migrate:status
php artisan migrate --pretend
php artisan migrate
```

Migration Core dan modul dimuat otomatis; tidak perlu publish migration.
Automotive tidak memiliki migration tambahan. `migrate` menjalankan semua
migration host yang pending, termasuk milik package lain. Periksa daftar dan SQL
sebelum menjalankannya. Jangan mengganti nama migration package lalu menjalankan
salinannya sebagai migration baru.

## 5. Master data dan pengaktifan fitur

Setelah migration, buat master melalui seeder/service host: organisasi dan gudang,
kategori barang, satuan dasar, barang, serta lokasi penyimpanan sesuai kebutuhan.
Gunakan ID master `inv_*` pada DTO Core. ID produk/gudang aplikasi lama tidak
otomatis sama dengan ID package. Untuk barang tracked, siapkan batch, serial,
expiry, dan sertifikat sesuai aturan item.

Semua perubahan stok dilakukan melalui `Inventory::post(DocumentData)` atau
service modul yang memanggil Core. Jangan mengisi ledger, cost layer, atau saldo
secara langsung. Contoh penerimaan tersedia pada [README](../README.md#posting-example);
reservasi dan fulfillment ada di [integrasi Sales/Purchasing](SALES_PURCHASING_INTEGRATION.md).

| Modul (suffix Composer) | Langkah setelah instalasi | Dokumentasi |
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

Stock opname memerlukan perhatian khusus: tipe `stock_opname`/`stock_count`
terdaftar tanpa pergerakan stok. Posting dokumen tersebut belum otomatis
menghitung dan membukukan selisih. Host perlu menghubungkan selisih yang disetujui
ke adjustment yang sesuai; lihat blocker pada [release notes](RELEASE_NOTES.md).

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

## 9. Masalah umum

| Gejala | Pemeriksaan |
|---|---|
| Modul tidak ditemukan Composer | Online: periksa URL katalog dan versi yang telah diterbitkan. Lokal: daftarkan path `packages/*`. VCS root saja hanya mengekspos Core. |
| `2.0.x-dev` tidak ditemukan | Versi ini khusus override path pada bagian 3B. Distribusi online memakai versi rilis katalog, bukan override lokal. |
| Repository lokal memblokir `dev-main` atau versi rilis | Hapus/ganti entri path yang sudah tidak digunakan; jangan mencampur sumber Core tanpa menentukan prioritas. |
| Core 2.x bertentangan dengan `^1.0` | Checkout modul belum berisi perbaikan constraint; pilih commit/rilis yang sudah diperbaiki. |
| Minimum stability menolak modul lokal | Gunakan `:@dev` pada modul yang dipilih; tidak perlu mengubah seluruh host menjadi minimum-stability dev. |
| Command/provider tidak ditemukan | Periksa package discovery dan autoload; jalankan `composer dump-autoload` dan `php artisan package:discover`. |
| Tabel sudah ada saat migration | Audit schema/migration lama; baseline bukan upgrade otomatis. |
| Konfigurasi baru tidak berlaku | Gabungkan file config yang sudah dipublish lalu clear/rebuild cache. |
| Composer menolak Laravel 9 karena advisory | Lihat matriks versi dan evaluasi upgrade host; pengecualian CI bukan perbaikan kerentanan. |
| Posting modul ditolak ketika accounting aktif | Periksa batasan Manufacturing/Food/Automotive di bagian 7. |
| Modul terpasang tetapi halaman/menu tidak ada | Buat integrasi UI/controller host; package menyediakan backend service. |

## 10. Maintainer: aktifkan distribusi dari satu repo

Patch menyediakan [workflow distribusi](../.github/workflows/distribute.yml) dan
[builder](../tools/distribute.py). Tidak perlu membuat repo tambahan. Target
workflow ini adalah repo publik agar URL Release assets dapat diunduh Composer
tanpa autentikasi. Untuk repo privat, rancang registry/auth terpisah sebelum
menggunakan URL publik contoh ini.

1. Commit/push tooling dan dokumentasi, serta pastikan semua modul meminta Core
   `^2.0`. Workflow harus ada di commit tag yang diterbitkan. Agar retry manual
   tersedia di UI Actions, workflow juga harus ada pada default branch.
2. Di GitHub **Settings > Pages > Build and deployment > Source**, pilih
   **GitHub Actions**. Pastikan environment `github-pages` mengizinkan tag/ref
   rilis dan deployment workflow. Pages akan digunakan untuk katalog Composer;
   jangan menimpa website Pages lain tanpa merencanakan lokasi hosting katalog.
3. Selesaikan blocker `IMPLEMENTATION_TODO.md`, jalankan
   `php tools/release-preflight.php`, dan pastikan workflow CI untuk commit itu
   berhasil. Pipeline distribusi tidak melewati gate ini. Blocker yang masih
   terbuka berarti publikasi belum dapat dilanjutkan.
4. Buat tag baru pada commit teruji, lalu publish GitHub Release untuk tag itu.
   Format yang diterima: `2.x.y`/`v2.x.y`, atau prerelease seperti `v2.0.2-RC1`.
   Semua sepuluh package memakai versi yang sama. Jangan mengubah tag lama.
5. Event `release.published` menjalankan **Distribute Composer packages**. Jika
   CI belum selesai, workflow gagal dengan pesan yang jelas; setelah CI lolos,
   jalankan ulang melalui **Run workflow** dengan input tag rilis yang sama.
6. Periksa Release assets: sepuluh ZIP dan `inventory-packages.json`. ZIP Core
   hanya berisi runtime Core; ZIP modul menempatkan `composer.json`, `src`, config,
   dan migration modul di root. Dev dependency, vendor, dan modul lain tidak
   dibundel. Provider auto-discovery tetap disertakan.
7. Periksa deployment Pages serta `packages.json`. Workflow menggabungkan metadata
   seluruh release publik yang memiliki `inventory-packages.json`, sehingga versi
   lama tidak hilang ketika rilis baru/versi lama diproses. Prerelease tetap
   mengikuti aturan stability Composer. Release lama sebelum tooling ini tidak
   otomatis dimasukkan karena tidak memiliki metadata/artifact terpisah.
8. Uji instalasi pada host disposable melalui bagian 3A sebelum mengumumkan URL.

Workflow memiliki izin write ke Release assets dan deploy Pages; tidak berjalan
pada pull request. Ia memeriksa CI sukses untuk SHA yang sama dan menjalankan
preflight release sebelum upload. Tidak ada dependency Composer yang dipasang oleh
builder. Upload ulang membandingkan isi asset dan menolak perubahan pada nama yang
sama, bukan memakai overwrite. Retry setelah upload/deploy parsial dapat memakai
tag yang sama selama isi tetap identik. Jangan menghapus release/artifact lama
yang masih digunakan lock file konsumen. Konflik versi metadata menggagalkan build.

Untuk memeriksa tooling secara lokal (Python 3.10+ dan Git), tanpa instalasi package:

```bash
python -m unittest discover -s tools/tests -p test_distribution.py
```

Untuk membangun ZIP dari tag lokal yang telah memuat constraint benar, ganti
`v2.0.2` berikut dengan tag Anda yang benar-benar tersedia:

```bash
python tools/distribute.py build --tag v2.0.2 --repository elgiborsolution/laravel-inventory
```

Output berada di `build/distribution` (diabaikan Git). Builder membaca snapshot
tag, bukan perubahan working tree. Perintah build tidak upload, deploy, atau
membuat tag. Subcommand `publish` dipakai workflow dengan GitHub CLI/token dan
melakukan upload nyata; jangan menjalankannya untuk sekadar pengecekan lokal.

Referensi lanjutan: [runbook rilis](ECOSYSTEM_RELEASE.md),
[API/schema](SOURCE_REFERENCE.md), dan [batas antar-package](architecture/PACKAGE_BOUNDARIES.md).
