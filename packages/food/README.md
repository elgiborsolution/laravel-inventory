# Laravel Inventory Food

`elgibor-solution/laravel-inventory-food` is an independent vertical that
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
