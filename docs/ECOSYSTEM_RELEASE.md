# Ecosystem installation and release runbook

Status: development baseline, NOT release-ready. Phase 14 adds local evidence;
it does not waive open Core acceptance criteria or external integration blockers.

## Install and configure

For the complete Bahasa Indonesia walkthrough, see
[installation for new and existing projects](INSTALLATION.md). It includes
commands for all nine optional modules, master data setup, legacy cutover,
external bridges, verification, and deployment.

1. Use an empty development database. Baseline migrations do not upgrade legacy data.
2. Install `elgibor-solution/laravel-inventory` once. Its runtime autoload and
   distribution include all nine modules. No separate module require is needed.
3. Let Composer discover the Core provider; remove manual module providers when
   using config-file activation.
4. Publish `inventory-config`, then only desired `inventory-<module>-config` tags.
   Core exposes every module tag before activation. Project now has a minimal
   config marker. Clear config cache before publishing; the next application boot
   registers providers for modules whose host config files exist.
5. Run `php artisan migrate`, then `php artisan inventory:validate-config`.
   Migrations are auto-loaded; do not rename and duplicate published migrations.
6. For enabled external integrations also run `inventory:accounting:validate`
   and `inventory:approval:validate`. Missing optional packages are not evidence
   that enabled integration prerequisites have been satisfied.
7. In a disposable host, run `php artisan config:cache`, repeat validation and a
   receipt/issue smoke, then `php artisan config:clear`. Module registration with cached configuration is tested; repeat this cycle
   with real host receipt/issue flows before deployment.

### Activating bundled modules

Modules are bundled in Core. To activate WMS after installation:

```bash
php artisan config:clear
php artisan vendor:publish --tag=inventory-wms-config
php artisan migrate
php artisan inventory:modules
```

Use the same publish convention for retail, manufacturing, healthcare, food,
asset, project, automotive, and library. No module flags or additional downloads
are required. Unpublished modules do not register migrations. Existing config
files activate modules automatically after upgrading to the bundle.

The distribution workflow now builds one Core ZIP including all runtime modules;
its cumulative Composer catalog retains old releases. Packagist/VCS installation
from a bundled release also contains the modules, without requiring Pages.
See [installation](INSTALLATION.md) for sources, upgrade, cache handling, and
maintainer release steps. Standalone module manifests remain internal catalog
metadata; Core replaces their Composer names to prevent duplicate installations.

For module purposes, features, examples, and host responsibilities, see the
[nine-module guide](INSTALLATION.md#51-kegunaan-fungsi-dan-fitur-sembilan-modul).

## Package combinations and ownership

| Package | Owned table prefix | Notes |
|---|---|---|
| Core | `inv_` | Includes `inv_cost_layers.batch_id`; owns all stock posting |
| Retail | `invr_` | Includes Consignment; settlement financial interpretation is host-owned |
| WMS | `invw_` | Physical tasks, no independent inventory ledger |
| Manufacturing | `invm_` | Calls Core for production effects |
| Healthcare | `invh_` | Tracking/recall, Core FEFO |
| Food | `invf_` | Recipes and production composition |
| Asset | `inva_` | Reservation-backed serialized loans |
| Project | `invp_` | Reservation-backed material allocation |
| Automotive | none | Reuses Core serial compliance and work-order issues |
| Library | `invl_` | Reservation-backed circulation and Hold queue |

Every vertical depends on Core, never a sibling. External accounting/approval
tables remain external. A vertical can call Core services to change Core-owned
state; direct ownership or a new posting engine is not permitted.

The automated Phase 14 SQLite smoke covers Core, every vertical separately,
Retail+Manufacturing, Healthcare+Retail, WMS+Healthcare, Food+WMS, Asset+Project,
Library+Retail, and the full catalog. Each case checks fresh migrations, repeat
migration, Core posting retry, receipt/issue, rollback and reinstall. Existing
vertical suites cover their business operations independently. Combined business
flows, external bridge combinations and Composer artifact installation still need
release-environment evidence. Do not use Asset and Library to allocate the same
Serial: their allocation constraints are not coordinated across verticals.

## APIs, configuration and extension points

Use `InventoryManager::post(DocumentData)` for stock effects. `reserve`, `release`,
`consume`, and `availability` expose Reservation and availability operations;
`resumeApproved` resumes approved documents. Use DTOs and validate host input
before invoking services; do not expose unrestricted model writes to callers.

See [generated source reference](SOURCE_REFERENCE.md) for service/contract methods
and schema migration links, individual package READMEs for domain APIs, and
[Accounting](ACCOUNTING_BRIDGE.md), [Approval](APPROVAL_BRIDGE.md), and
[Sales/Purchasing](SALES_PURCHASING_INTEGRATION.md) for integration examples.

Core config covers organization/storage levels, costing scope, movement model,
negative stock, idempotency and optional bridges. Configuration-depth and history
behavior must be verified before changing active host configuration. Do not treat
published defaults as an authorization or tenant-isolation boundary.

Contracts include CostingDriver, MovementPolicy/MovementPolicyRegistry,
DocumentTypeRegistry, AccountingBridge/AccountingJournalGateway and
ApprovalBridge/ApprovalWorkflowGateway. Register host implementations through the
container and supported registries, not by editing vendor code.

`DocumentPosted` exists, but after-commit delivery guarantees remain an open Core
acceptance item. An `events.after_commit` setting alone does not establish reliable
delivery. Do not depend on unverified hooks for irreversible external side effects.
Retail settlement and Library expiry require host scheduling where applicable;
use a shared lock backend for overlap prevention and idempotent job handlers.
Scheduler/retry guarantees across hosts remain a release verification item.

## Uninstall and troubleshooting

To disable a bundled module, stop its host jobs/writes, remove manual provider
registrations and move its `config/inventory-<module>.php` file out of the host
config directory. Clear/rebuild config cache and restart long-running workers.
Do not remove a standalone Composer dependency: module code ships inside Core.
Data and tables remain, and host code must stop calling the disabled module.
See [module status and deactivation](INSTALLATION.md#9-status-modul-dan-penonaktifan).

Before removing Core itself, back up its tables and remove host service/provider
references. Removing the dependency does not remove data. Preserve historical records.
Do not use a global `migrate:rollback` on a live host to uninstall one package:
it can include other packages in the same batch. Test dependency-aware teardown
only on a disposable clone with an explicit approved data-retention plan.

- Class/provider missing: verify the selected package autoload and discovery.
- Missing table: check migration registration/order and the database connection.
- Missing mapping or rejection policy: resolve the documented bridge prerequisites;
  do not bypass validation to make posting succeed.
- SQLite busy error: retry the whole idempotent operation; do not retry partial writes.
- Stale config: clear/rebuild host config cache after approved configuration changes.

## Release evidence and blocking policy

Run `composer check`, `composer validate --strict`, and dependency audit in the
release environment. Run `composer release:preflight` to reject unchecked P0,
acceptance, gate and blocker entries in IMPLEMENTATION_TODO.md. The checker is
read-only, intentionally conservative, and is also run by CI for tag refs.
It cannot prevent a local git tag or replace server-side release protection.
It is not part of ordinary green development checks because the baseline has
known blockers. No tag or artifact is created by these commands.

Before any release, attach evidence for:

- Clean Composer install and config publishing/cache in real hosts for supported versions.
- Full suites and real multi-connection races on each supported database.
- Accounting-only, Approval-only, both installed, and both absent with real packages;
  fake gateway contract tests do not establish external version compatibility.
- Posting rollback, approval pause/resume, idempotent retry and after-commit delivery.
- Representative combined business flows, including Consignment behavior.
- Security review and benchmark results with agreed dataset sizes and thresholds.

Benchmarks must include ledger/Stock Card reads, cost-layer locking, Reservations,
FEFO and reporting. Record engine/version, row counts, query plans, latency percentiles,
lock waits and concurrent worker counts. No target volumes or production benchmark
environment are provided by this repository; no performance SLA is claimed.

## Security review notes (not a completed audit)

Item and other Eloquent models permit broad assignment (`guarded = []`): the host
must allowlist request fields and enforce authorization before service/model access.
Organization and warehouse IDs are data scope, not proof that the caller may access
them. Validate polymorphic type/ID references against an allowed mapping and caller
scope. Services are not a built-in authentication or multi-tenant security layer.
Attachment upload, MIME/size validation, storage access, malware scanning and URL
authorization remain host responsibilities. Verify cross-organization denial,
forged references and attachment access in the host before enabling these features.
