# Unreleased baseline

## Tenda integration prerequisites

- Added standard untracked FIFO warehouse transfer and linked exact-cost reversal.
- Added item-level serialization with ordinary posting and a unique reversal index.
- Added rollback, retry, costing and opt-in real-database concurrency scenarios.
- See [supported scope and testing](TRANSFER_REVERSAL.md); this does not close the
  general release gate or implement tracked/bridge-enabled transfer and reversal.

## Phase 14 additions

- SQLite installation/coexistence matrix: Core, nine verticals, six representative
  pairs and the full catalog; repeat migrations, Core posting retry and teardown/reinstall.
- Complete-catalog dependency and duplicate migration/table checks.
- Generated service/contract and migration reference with a freshness check.
- Conservative read-only release preflight; CI rejects tagged builds with open checklist blockers.
- Installation, ownership, operational and security-review guidance.

## Known release blockers

The authoritative register is [IMPLEMENTATION_TODO](../IMPLEMENTATION_TODO.md).
Phase 14 is not complete and RELEASE-GATE remains open. Existing Core TODO tests
include after-commit events, broader real database races, constraints and stock-count
variance orchestration. Passing implemented tests does not close TODOs.

External Accounting service coverage for Manufacturing, Food and Automotive,
Approval rejection decisions, real bridge combinations, database/version matrices,
performance evidence and full security review remain prerequisites where enabled.
Fail-closed domain modules must stay fail-closed until prerequisites are verified.
Fresh installs only; there is no legacy production migration tool or built-in UI.
Loan Serial lifecycle and cross-vertical allocation are host integration constraints.
No release tag has been created by this patch.
