# Package Boundaries and Naming

The TSD uses placeholder vendor names. This repository translates them to the
following fixed `ESolution` identities while preserving the documented package
boundaries.

| Package | Composer name | PHP namespace | Table prefix |
|---|---|---|---|
| Core | `elgibor-solution/laravel-inventory` | `ESolution\Inventory\` | `inv_` |
| Retail | `elgibor-solution/laravel-inventory-retail` | `ESolution\InventoryRetail\` | `invr_` |
| WMS | `elgibor-solution/laravel-inventory-wms` | `ESolution\InventoryWms\` | `invw_` |
| Manufacturing | `elgibor-solution/laravel-inventory-manufacturing` | `ESolution\InventoryManufacturing\` | `invm_` |
| Healthcare | `elgibor-solution/laravel-inventory-healthcare` | `ESolution\InventoryHealthcare\` | `invh_` |
| Food | `elgibor-solution/laravel-inventory-food` | `ESolution\InventoryFood\` | `invf_` |
| Asset | `elgibor-solution/laravel-inventory-asset` | `ESolution\InventoryAsset\` | `inva_` |
| Project | `elgibor-solution/laravel-inventory-project` | `ESolution\InventoryProject\` | `invp_` |
| Automotive | `elgibor-solution/laravel-inventory-automotive` | `ESolution\InventoryAutomotive\` | `invat_` (reserved) |
| Library | `elgibor-solution/laravel-inventory-library` | `ESolution\InventoryLibrary\` | `invl_` |

External packages retain their real identities:

| Integration | Composer name | PHP namespace | Table prefix |
|---|---|---|---|
| Accounting | `elgibor-solution/laravel-accounting` | `ESolution\LaravelAccounting\` | `acc_` |
| Approval | `e-solution/laravel-approval-flow` | `ESolution\ApprovalFlow\` | `approval_` |

## Binding Rules

- A vertical may reference Core through PHP contracts and may FK from its own
  table into `inv_*`.
- Core must not import a vertical namespace, depend on a vertical Composer
  package, or create a FK into a vertical table.
- A vertical must not import or FK into another vertical.
- Core and verticals must not create or FK into external integration tables.
- External/business-flexible identities use string-safe polymorphic references.
- `inv_cost_layers.batch_id` is Core-owned; Healthcare must not alter the Core table.

These rules are enforced by `tests/Architecture/DependencyBoundariesTest.php` and CI.

