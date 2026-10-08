# Laravel Inventory

`elgibor-solution/laravel-inventory` provides an extensible Laravel Inventory
Core with scoped stock, append-only ledger, costing layers, tracking, document
workflow, Stock Cards, and reservations. The package keeps its public namespace
at `ESolution\Inventory`.

Accounting and approval are optional bridges. Inventory Core does not implement
a General Ledger and does not own journal tables.

## Installation

Core now bundles all nine modules with runtime autoload. Install Core once;
activate a module by publishing its configuration, then migrate on the next
Artisan invocation. No module Composer require or activation boolean is needed.
Existing published module configuration activates that module after upgrading.

```bash
php artisan config:clear
php artisan vendor:publish --tag=inventory-wms-config
php artisan migrate
php artisan inventory:modules
```

Panduan Bahasa Indonesia: [instalasi project baru dan project lama](docs/INSTALLATION.md),
termasuk aktivasi modul, konfigurasi, migrasi data lama,
integrasi Accounting/Approval, verifikasi, dan deployment.
Lihat [kegunaan, fungsi, dan fitur sembilan modul](docs/INSTALLATION.md#51-kegunaan-fungsi-dan-fitur-sembilan-modul)
untuk contoh penggunaan dan batas tanggung jawab aplikasi host.

```bash
composer require elgibor-solution/laravel-inventory
php artisan vendor:publish --tag=inventory-config
php artisan migrate
php artisan inventory:validate-config
```

For a local path repository, require the development version after registering
the repository in the host project's `composer.json`:

```bash
composer require elgibor-solution/laravel-inventory:@dev
```

Module code lives in `packages/*` inside the installed Core package. The Core
manifest replaces the old standalone module package names to avoid duplicate
classes. See the [upgrade guide](docs/INSTALLATION.md) before switching an existing
installation with separately installed modules.

## Configuration

The published `config/inventory.php` controls organization/storage depth,
costing scope, negative-stock policy, idempotency, optional bridges, and
after-commit behavior. When upgrading, compare and merge configuration changes
manually to preserve project-specific values; do not force-overwrite host config.

Accounting is off by default:

```php
'accounting' => [
    'enabled' => false,
    'connection' => null,
    'tenant_payload_key' => null,
    'service_code_map' => [
        'purchase_receipt' => 'PURCHASE_CREDIT',
        'warehouse_transfer.intra_company' => null,
    ],
],
```

When enabling it, install `elgibor-solution/laravel-accounting` in the host
project and validate deployment prerequisites:

```bash
php artisan inventory:accounting:validate
```

See [Accounting Bridge](docs/ACCOUNTING_BRIDGE.md) for mapping, transaction,
tenant, and reversal behavior.

Approval Flow is also optional. When `e-solution/laravel-approval-flow` is
installed, validate its identity resolver, status field, service-auth posture,
and published workflows:

```bash
php artisan inventory:approval:validate
```

See [Approval Bridge](docs/APPROVAL_BRIDGE.md) for submit, callback, status
ownership, rejection, cancellation, and idempotent resume behavior.

For Sales reservation, atomic partial fulfillment, walk-in sale, Purchasing
receipt, and availability examples, see
[Sales and Purchasing Integration](docs/SALES_PURCHASING_INTEGRATION.md).

The optional Retail vertical is bundled under the historical
`elgibor-solution/laravel-inventory-retail` package under `packages/retail`.
See [Retail package documentation](packages/retail/README.md) for stock-bearing
variant matrices, Consignment, POS, and E-Commerce integration.

The optional WMS vertical is bundled under the historical
`elgibor-solution/laravel-inventory-wms` package under `packages/wms`. See
[WMS package documentation](packages/wms/README.md) for put-away/picking
strategies, tasks, waves, LPNs, replenishment, cross-docking, and the TMS
integration pattern.

The optional Manufacturing vertical is bundled under the historical
`elgibor-solution/laravel-inventory-manufacturing` package under
`packages/manufacturing`. See [Manufacturing package documentation](packages/manufacturing/README.md)
for immutable versioned BOMs, atomic production orchestration, WIP chaining,
variance tracking, and the accounting blocker.

The optional Healthcare vertical is bundled under the historical
`elgibor-solution/laravel-inventory-healthcare` package under
`packages/healthcare`. See [Healthcare package documentation](packages/healthcare/README.md)
for the tracking preset, Core-owned deterministic FEFO, controlled expired
receipts, COA enforcement, recall veto, and forward traceability.

The optional Food vertical is bundled under the historical
`elgibor-solution/laravel-inventory-food` package under `packages/food`. See
[Food package documentation](packages/food/README.md) for immutable versioned
Recipes, idempotent MTO triggers, atomic RecipeBatch actual-cost roll-up, the
Halal tracking preset, optional Core FEFO, and the accounting blocker.

The optional Asset vertical is bundled under the historical
`elgibor-solution/laravel-inventory-asset` package under `packages/asset`. See
[Asset package documentation](packages/asset/README.md) for serialized checkout,
portable active-allocation protection, Reservation-based loans, derived overdue
notification, and the documented Core serial-status limitation.

The optional Project vertical is bundled under the historical
`elgibor-solution/laravel-inventory-project` package under `packages/project`.
See [Project package documentation](packages/project/README.md) for exact
Reservation-backed allocations, replenishment, atomic reallocation, partial
material draw, and allocation reporting without new stock behavior or presets.

The bundled [Library module](packages/library/README.md) provides
per-copy circulation, reservation-backed ready Holds, renewals, derived overdue,
and fine records. Waiting Holds do not reserve stock.

The optional Automotive package under `packages/automotive` provides serial
Compliance tracking, ordinary Core work-order parts issues, and usage reporting.
See [Automotive documentation](packages/automotive/README.md) for external
references and the fail-closed accounting decision.

## Posting example

```php
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Facades\Inventory;

$document = Inventory::post(new DocumentData(
    type: 'purchase_receipt',
    organizationId: $organizationId,
    trxDate: now()->toDateString(),
    externalId: 'GR-001',
    lines: [
        new LineData(
            itemId: $itemId,
            uomId: $uomId,
            warehouseId: $warehouseId,
            qty: 10,
            unitCost: 5000,
        ),
    ],
));
```

With Accounting Bridge enabled, caller-owned financial lines can be forwarded
without Core calculating revenue or tax:

```php
additionalJournalLines: [
    ['mapping_key' => 'purchase_credit_ap_k', 'amount' => 50000],
],
```

## Development checks

For atomic standard FIFO warehouse transfers and exact-cost corrections, see
[Transfer and reversal](docs/TRANSFER_REVERSAL.md). These operations currently
support untracked stock with Accounting and Approval bridges disabled.

This is an unreleased development baseline. See the
[ecosystem installation and release runbook](docs/ECOSYSTEM_RELEASE.md),
[generated API/schema reference](docs/SOURCE_REFERENCE.md), and
[known release blockers](docs/RELEASE_NOTES.md). Passing implemented tests does
not mean open TODO tests or the production release matrix are complete.

```bash
composer check
composer audit
composer validate --strict
composer release:preflight
```

The fresh baseline migrations are intended for new installations. Migration of
legacy production data is outside the baseline package.

## License

Apache-2.0
