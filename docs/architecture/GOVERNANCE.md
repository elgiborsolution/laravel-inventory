# Inventory Ecosystem Governance

## Authority and Change Control

The numbered documents under `D:\Project\flow_inventory\PRD` and
`D:\Project\flow_inventory\TSD` are the specification of record. Read PRD-0 and
TSD-0 before every package-specific pair. When documents conflict, the lower
numbered governance document wins until it is amended; implementation must not
silently choose a different behavior.

Changes that affect a public contract, package boundary, table ownership,
posting lifecycle, or cross-package integration require:

1. an amendment to the owning PRD/TSD;
2. an entry in the changelog of that document;
3. updated acceptance criteria and automated tests;
4. semantic-version classification before merge.

## Fixed Repository Decisions

- Core keeps Composer name `elgibor-solution/laravel-inventory`.
- Core keeps PHP namespace `ESolution\Inventory\`.
- The implementation is greenfield. Current code is reference material and is
  replaced subsystem by subsystem according to `LEGACY_DISPOSITION.md`.
- Baseline v1 supports fresh database installation only. Legacy production-data
  migration is a separate project and must never be hidden in baseline migrations.
- Core uses `inv_` tables and never owns `acc_*`, `approval_*`, or vertical tables.
- Accounting and Approval are optional, config-gated external integrations.
- Every vertical depends on Core only. Verticals never depend on one another.

## Versioning

- **Major:** required public-contract method/signature changes, namespace/table
  ownership changes, or breaking external bridge API changes.
- **Minor:** backward-compatible capabilities, optional contract additions, new
  Document Types, commands, events, or configuration keys.
- **Patch:** compatible bug fixes and internal changes with no schema/API break.

External Accounting and Approval APIs must be re-audited whenever either ships a
new major version. The Core release that adopts that major is itself a major release.

## Definition of Done

A change is complete only when implementation, automated acceptance tests,
architecture checks, static analysis, formatting, documentation, and supported
matrix jobs pass. Manual verification cannot be the sole evidence for a PRD AC.

## Review Ownership

- Core maintainers approve Core contracts, `inv_*` schema, posting, ledger, and costing.
- A vertical maintainer owns only its namespace and table prefix.
- Integration maintainers re-verify external bridge signatures and prerequisites.
- Product ownership resolves open business decisions before a blocker is closed.

