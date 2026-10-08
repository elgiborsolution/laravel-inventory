# Supported Versions and CI Matrix

The package declares the following target CI matrix. A configured row is not proof
of a successful run; retain actual CI results before claiming release compatibility.
Core and all nine bundled modules require PHP `>=8.2`. PHP 8.1 and earlier are
not supported. Framework and dependency requirements may impose a higher minimum.

| Laravel | PHP | Orchestra Testbench | Pest | Larastan |
|---|---|---|---|---|
| 9 | 8.2 | 7.x | 1.23.x | 2.x |
| 10 (>=10.50.3) | 8.2 | 8.x | ^2.36.1 | 2.x |
| 11 (>=11.57) | 8.2 | 9.x | 3.x | 2.x |
| 12 | 8.2 | 10.x | 3.x | 3.x |
| 13 | 8.3 | 11.x | 4.x | 3.x |

SQLite is used for the fast package suite. Posting/concurrency and migration
portability must additionally run against current supported MySQL and PostgreSQL
in the integration pipeline before GA.

### Laravel 9 runtime compatibility

Laravel 9 does not provide `ShouldDispatchAfterCommit`. Core posting, transfers,
and reversals use `DocumentPosted::dispatchAfterCommit()` instead: a callback on
the document's database connection defers delivery until the outer transaction
commits and is discarded on rollback. Without an active transaction delivery is
immediate. Host code that emits this event should use this method, not
`event(new DocumentPosted(...))`, which dispatches immediately.

Module tests isolate config/bootstrap paths through Application path overrides;
they do not call `useConfigPath()` or `useBootstrapPath()`, absent in Laravel 9.
These fixes do not close the broader event lifecycle/release acceptance checklist.

### Framework-compatible development tools

Laravel 9 uses Testbench 7, Pest `^1.23.1`, and PHPUnit `^9.6.34`. Testbench 7
requires PHPUnit 9; Pest 2 and PHPUnit 10 cannot be combined with it. CI selects
`phpunit9.xml.dist` for this row because the default PHPUnit 11 XML schema is not
compatible with PHPUnit 9. The TestCase todo fallback keeps unfinished criteria
incomplete when running Pest 1; it does not mark them as passed.

Laravel 10 runs on PHP 8.2 with Pest `^2.36.1`. The previous PHP 8.1-only
Pest/ PHPUnit pins and PHPUnit advisory exception have been removed. Composer
selects the compatible PHPUnit version subject to normal dependency security blocking.

The coding-standard job uses PHP 8.3 with normal security blocking. Consumers do
not inherit CI-only framework selections or advisory exceptions.

### Legacy compatibility and security audits

The Laravel 9 / PHP 8.2 row is legacy compatibility coverage, not a claim that
Laravel 9 dependencies are free of security advisories. Composer can reject
Testbench 7's Laravel 9 dependency before any package tests execute.

Only this CI row temporarily configures the six known advisory IDs listed in
`.github/workflows/ci.yml` with `audit.ignore` scoped to `apply: block`. This
allows dependency resolution while preserving advisory reports. The setting is
written only in the disposable CI checkout, not in the published manifest or a
consumer application's Composer configuration. New advisory IDs still block
resolution and require review.

The Laravel 10 row selects framework `^10.50.3` in the disposable CI checkout.
It exempts only four framework advisories
from dependency blocking: `PKSA-d5tc-s1qs-h781`, `PKSA-m5cs-t1y6-qpcs`,
`PKSA-3r5d-mb8f-1qw9`, and `PKSA-mdq4-51ck-6kdq`. These remain visible in audits.
The file-validation and environment-manipulation advisories fixed in earlier
Laravel 10 patches are not exempted. The PHP step merges these IDs into
`config.audit.ignore` without discarding existing entries. This does not change
the published manifest's runtime constraints or consumer security configuration.

The Laravel 11 row selects framework `^11.57` in the CI checkout and exempts
only four advisories still affecting that version from dependency blocking:
`PKSA-d5tc-s1qs-h781`, `PKSA-m5cs-t1y6-qpcs`, `PKSA-3r5d-mb8f-1qw9`, and
`PKSA-mdq4-51ck-6kdq`. Advisories fixed in earlier Laravel 11 patches are not
exempted. Testbench remains on `^9.0`; neither PHP platform requirements nor
security blocking for unrelated advisories are disabled. This CI-only framework
minimum does not change the package's runtime Illuminate constraints.

On branch and pull-request runs, the Laravel 9, 10, and 11 rows report audit failures as warnings
so compatibility tests can run. On release tags, their audits remain blocking.
Other matrix rows retain strict dependency blocking and audits. A passing legacy
compatibility job is not a security approval for production deployment.

See [Composer audit ignore scoping](https://getcomposer.org/doc/06-config.md#ignore)
for the `apply: block` behavior and legacy configuration support.

Package compatibility fixes do not provide upstream Laravel security fixes.
New framework majors are added only after their matrix row is green. Unsupported
rows must be removed from both Composer constraints and this document together.
