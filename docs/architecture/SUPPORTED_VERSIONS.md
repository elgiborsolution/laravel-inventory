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

The oldest supported Laravel/PHP pair receives security and compatibility fixes.
New framework majors are added only after their matrix row is green. Unsupported
rows must be removed from both Composer constraints and this document together.
