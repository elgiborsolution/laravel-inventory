# Supported Versions and CI Matrix

The package declares the following target CI matrix. A configured row is not proof
of a successful run; retain actual CI results before claiming release compatibility.

| Laravel | PHP | Orchestra Testbench | Pest | Larastan |
|---|---|---|---|---|
| 9 | 8.1 | 7.x | 1.23.x | 2.x |
| 10 | 8.1 | 8.x | 2.36.0 | 2.x |
| 11 (>=11.57) | 8.2 | 9.x | 3.x | 2.x |
| 12 | 8.2 | 10.x | 3.x | 3.x |
| 13 | 8.3 | 11.x | 4.x | 3.x |

SQLite is used for the fast package suite. Posting/concurrency and migration
portability must additionally run against current supported MySQL and PostgreSQL
in the integration pipeline before GA.

### PHP 8.1 compatibility tools

Laravel 9 uses Testbench 7, Pest `^1.23.1`, and PHPUnit `^9.6.34`. Testbench 7
requires PHPUnit 9; Pest 2 and PHPUnit 10 cannot be combined with it. CI selects
`phpunit9.xml.dist` for this row because the default PHPUnit 11 XML schema is not
compatible with PHPUnit 9. The TestCase todo fallback keeps unfinished criteria
incomplete when running Pest 1; it does not mark them as passed.

Only Laravel 10 / PHP 8.1 pins Pest to `2.36.0` and PHPUnit to `10.5.36` in the
disposable CI checkout. Pest 2.36.0 requires PHPUnit `^10.5.36` but also conflicts with every
version above `10.5.36`; Pest 2.36.1 requires PHP 8.2. Updating with `-W` does not
resolve that combination on PHP 8.1.

PHPUnit 10.5.36 is affected by `PKSA-z3gr-8qht-p93v` (unsafe deserialization in
PHPT coverage handling), fixed in the 10.x series in 10.5.62. Only the Laravel 10 /
PHP 8.1 compatibility job adds a block-only exception for that advisory. This does not
fix the vulnerability or establish security support for these test tools.
New advisory IDs still block dependency resolution.

References: [Pest 2.36.0 manifest](https://github.com/pestphp/pest/blob/v2.36.0/composer.json),
[Pest 2.36.1 manifest](https://github.com/pestphp/pest/blob/v2.36.1/composer.json),
[PHPUnit advisory](https://github.com/sebastianbergmann/phpunit/security/advisories/GHSA-vvj3-c3rp-c85p).

The coding-standard job uses PHP 8.3 with normal security blocking. The Core
runtime PHP constraint is unchanged; consumers do not inherit these CI-only
development pins or advisory exceptions.

### Legacy compatibility and security audits

The Laravel 9 / PHP 8.1 row is legacy compatibility coverage, not a claim that
Laravel 9 dependencies are free of security advisories. Composer can reject
Testbench 7's Laravel 9 dependency before any package tests execute.

Only this CI row temporarily configures the six known advisory IDs listed in
`.github/workflows/ci.yml` with `audit.ignore` scoped to `apply: block`. This
allows dependency resolution while preserving advisory reports. The setting is
written only in the disposable CI checkout, not in the published manifest or a
consumer application's Composer configuration. New advisory IDs still block
resolution and require review.

The Laravel 11 row selects framework `^11.57` in the CI checkout and exempts
only four advisories still affecting that version from dependency blocking:
`PKSA-d5tc-s1qs-h781`, `PKSA-m5cs-t1y6-qpcs`, `PKSA-3r5d-mb8f-1qw9`, and
`PKSA-mdq4-51ck-6kdq`. Advisories fixed in earlier Laravel 11 patches are not
exempted. Testbench remains on `^9.0`; neither PHP platform requirements nor
security blocking for unrelated advisories are disabled. This CI-only framework
minimum does not change the package's runtime Illuminate constraints.

On branch and pull-request runs, both PHP 8.1 rows and Laravel 11 report audit failures as warnings
so compatibility tests can run. On release tags, their audits remain blocking.
Other matrix rows retain strict dependency blocking and audits. A passing legacy
compatibility job is not a security approval for production deployment.

See [Composer audit ignore scoping](https://getcomposer.org/doc/06-config.md#ignore)
for the `apply: block` behavior and legacy configuration support.

Package compatibility fixes do not provide upstream Laravel security fixes.
New framework majors are added only after their matrix row is green. Unsupported
rows must be removed from both Composer constraints and this document together.
