# Supported Versions and CI Matrix

The package declares the following target CI matrix. A configured row is not proof
of a successful run; retain actual CI results before claiming release compatibility.

| Laravel | PHP | Orchestra Testbench | Pest | Larastan |
|---|---|---|---|---|
| 9 | 8.1 | 7.x | 2.x | 2.x |
| 10 | 8.1 | 8.x | 2.x | 2.x |
| 11 | 8.2 | 9.x | 3.x | 2.x |
| 12 | 8.2 | 10.x | 3.x | 3.x |
| 13 | 8.3 | 11.x | 4.x | 3.x |

SQLite is used for the fast package suite. Posting/concurrency and migration
portability must additionally run against current supported MySQL and PostgreSQL
in the integration pipeline before GA.

### Laravel 9 compatibility and security audits

The Laravel 9 / PHP 8.1 row is legacy compatibility coverage, not a claim that
Laravel 9 dependencies are free of security advisories. Composer can reject
Testbench 7's Laravel 9 dependency before any package tests execute.

Only this CI row temporarily configures the six known advisory IDs listed in
`.github/workflows/ci.yml` with `audit.ignore` scoped to `apply: block`. This
allows dependency resolution while preserving advisory reports. The setting is
written only in the disposable CI checkout, not in the published manifest or a
consumer application's Composer configuration. New advisory IDs still block
resolution and require review.

On branch and pull-request runs, this row's audit failure is reported as a warning
so compatibility tests can run. On release tags, its audit remains blocking.
Other matrix rows retain strict dependency blocking and audits. A passing legacy
compatibility job is not a security approval for production deployment.

See [Composer audit ignore scoping](https://getcomposer.org/doc/06-config.md#ignore)
for the `apply: block` behavior and legacy configuration support.

Package compatibility fixes do not provide upstream Laravel security fixes.
New framework majors are added only after their matrix row is green. Unsupported
rows must be removed from both Composer constraints and this document together.
