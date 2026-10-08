# Supported Versions and CI Matrix

The package declares the following target CI matrix. A configured row is not proof
of a successful run; retain actual CI results before claiming release compatibility.
Core and all nine bundled modules require PHP `>=8.2`. PHP 8.1 and earlier are
not supported. Core requires Illuminate `^11.0|^12.0|^13.0`, so Laravel 10 and
earlier, and Laravel 14 or later, are outside the supported range. All bundled
modules inherit this restriction through Core. Framework and dependency
requirements may impose a higher PHP minimum.

| Laravel | PHP | Orchestra Testbench | Pest | Larastan |
|---|---|---|---|---|
| 11 (>=11.57) | 8.2 | 9.x | 3.x | 2.x |
| 12 | 8.2 | 10.x | 3.x | 3.x |
| 13 | 8.3 | 11.x | 4.x | 3.x |

SQLite is used for the fast package suite. Posting/concurrency and migration
portability must additionally run against current supported MySQL and PostgreSQL
in the integration pipeline before GA.

### Development tools and CI audits

Testbench 9/10/11 covers Laravel 11/12/13 respectively. Pest 3/4 is used according
to the matrix above. Laravel 9/10 CI jobs and their legacy test-tool pins and
advisory exceptions have been removed. The coding-standard job uses PHP 8.3.
Consumers do not inherit CI-only framework selections or advisory exceptions.

The Laravel 11 row selects framework `^11.57` in the CI checkout and exempts
only four advisories still affecting that version from dependency blocking:
`PKSA-d5tc-s1qs-h781`, `PKSA-m5cs-t1y6-qpcs`, `PKSA-3r5d-mb8f-1qw9`, and
`PKSA-mdq4-51ck-6kdq`. Advisories fixed in earlier Laravel 11 patches are not
exempted. Testbench remains on `^9.0`; neither PHP platform requirements nor
security blocking for unrelated advisories are disabled. This CI-only framework
minimum does not change the package's runtime Illuminate constraints.

On branch and pull-request runs, the Laravel 11 row reports audit failures as warnings
so compatibility tests can run. On release tags, their audits remain blocking.
Other matrix rows retain strict dependency blocking and audits. A passing legacy
compatibility job is not a security approval for production deployment.

See [Composer audit ignore scoping](https://getcomposer.org/doc/06-config.md#ignore)
for the `apply: block` behavior and legacy configuration support.

Package compatibility fixes do not provide upstream Laravel security fixes.
New framework majors are added only after their matrix row is green. Unsupported
rows must be removed from both Composer constraints and this document together.
