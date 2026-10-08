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

## Purchase, sale, and stock-count calculations

- Moving Average now values actual posting from scoped ledger quantity/value;
  FIFO remains the default and its existing behavior is retained.
- Optional LineData transaction pricing/discounts and `Inventory::stockCard()`
  expose per-line balances, COGS, profit, and running sales without new tables.
- Untracked base-UOM stock counts post only their variance, including zero physical
  counts, retry, and approval resume. Tracked counts and count-session freeze remain
  outside this implementation.
- Adjustment and supplier-return journals require explicit verified host mapping
  roles. Supplier refunds use invoice price and recognize the book-value difference.
- Negative Moving Average stock and non-FIFO standard transfer/reversal remain
  unsupported. Historical FIFO postings are not recalculated.
- See [business calculations](SALES_PURCHASING_INTEGRATION.md#business-calculations)
  for setup, compatibility, host responsibilities, and reporting order.

## Known release blockers

The authoritative register is [IMPLEMENTATION_TODO](../IMPLEMENTATION_TODO.md).
Phase 14 is not complete and RELEASE-GATE remains open. Existing Core TODO tests
include broader real database races, constraints, and Weighted Average posting. Passing implemented tests does not close TODOs.

External Accounting service coverage for Manufacturing, Food and Automotive,
Approval rejection decisions, real bridge combinations, database/version matrices,
performance evidence and full security review remain prerequisites where enabled.
Fail-closed domain modules must stay fail-closed until prerequisites are verified.
Fresh installs only; there is no legacy production migration tool or built-in UI.
Loan Serial lifecycle and cross-vertical allocation are host integration constraints.
No release tag has been created by this patch.
