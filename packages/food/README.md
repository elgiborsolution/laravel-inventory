# Laravel Inventory Food

## Kegunaan dan fitur

**Kegunaan:** Produksi makanan berdasarkan resep untuk stok atau pesanan.

**Fungsi dan fitur:** Recipe berversi, RecipeBatch dengan konsumsi bahan/penerimaan hasil atomik, biaya aktual bahan, produksi MTS (untuk stok) dan MTO (sesuai pesanan), serta preset batch dan persyaratan sertifikat halal.

**Contoh penggunaan:** Memproduksi satu batch makanan dari resep dan membukukan biaya bahan ke hasil produksi.

**Batas dan integrasi host:** FEFO dapat diatur melalui Core tanpa Healthcare/WMS. Penyelesaian RecipeBatch saat ini memerlukan accounting Core/Food nonaktif; integrasi pesanan dan UI tetap milik host.

Lihat [perbandingan sembilan modul](../../docs/INSTALLATION.md#51-kegunaan-fungsi-dan-fitur-sembilan-modul)
untuk memilih modul yang sesuai.

## Activation

Bundled installation: install Core once, then run `php artisan config:clear`,
`php artisan vendor:publish --tag=inventory-food-config`, and
`php artisan migrate`. Check `php artisan inventory:modules`. No separate module
download is needed. Core registers the module provider on the next boot when
its host config file exists; do not register a module provider manually for this
activation flow. See [installation and upgrade](../../docs/INSTALLATION.md) for
config cache, worker restarts, upgrades, and deactivation.

## Technical behavior

`elgibor-solution/laravel-inventory-food` is a bundled module that
depends only on Inventory Core and owns all `invf_*` tables.

Published Recipe versions and their components are immutable. A `RecipeBatch`
pairs ordinary Core `recipe_consumption` and `recipe_receipt` documents in one
transaction and rolls the actual consumed component cost into the output.

The configurable `food_order` Core transition hook creates one MTO RecipeBatch
per source line carrying `meta.recipe_version_id`. The source document and line
unique key makes repeated delivery idempotent. MTS batches are created directly
through `RecipeBatchService`.

`FoodPreset` merges mandatory receipt batch tracking and the `halal` certificate
requirement with existing Item tracking rules. Food intentionally does not
depend on Healthcare or WMS. Projects that need FEFO may configure the Core Item
with `costing_method = fefo` and expiry tracking; Healthcare is not required.

Food accounting remains fail-closed while verified accounting service codes are
unavailable. Both Core and Food accounting must remain disabled for RecipeBatch
completion.
