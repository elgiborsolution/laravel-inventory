# Inventory Ecosystem — Master Implementation Checklist

> Status: planning baseline  
> Strategy: greenfield implementation in the existing repository  
> Composer package: `elgibor-solution/laravel-inventory`  
> PHP namespace: `ESolution\Inventory`  
> Sources of truth: `D:\Project\flow_inventory\PRD`, `D:\Project\flow_inventory\TSD`, and `developer_guide.md`

## How to Use This Checklist

- A task is complete only when its implementation, automated tests, and referenced acceptance criteria pass.
- Priority: **P0** blocks dependent work/release, **P1** is required before GA, **P2** may follow after the owning phase's MVP.
- Dependencies are written as `Depends on: Phase N` or a specific task/gate.
- Requirement references use their original IDs. Read the referenced PRD/TSD before implementation; this checklist does not replace them.
- Do not mark an AC complete from manual inspection alone. Each AC requires an automated test named after the AC, for example `test_ac_04_posted_ledger_is_immutable`.
- A phase is complete only after its **Exit Gate** is checked.
- Legacy code is reference material only. Do not preserve a legacy behavior that conflicts with PRD/TSD.

## Definition of Done

- [ ] **P0** Code follows one-way dependency rules: vertical → Core; no vertical imports another vertical.
- [ ] **P0** Core contains no vertical namespace imports and no Core FK points into a vertical table.
- [ ] **P0** Posted ledger entries are append-only; correction is implemented only through reversal.
- [ ] **P0** Every state-changing public operation is transactional, validates its domain invariants, and has retry/idempotency behavior documented and tested.
- [ ] **P0** Concurrency-sensitive paths use database constraints and row locks; application-level `exists()` checks are not the sole guard.
- [ ] **P0** Every PRD acceptance criterion in scope has an automated test and traceable test name.
- [ ] **P1** Events use after-commit semantics; listeners never observe transactions that may roll back.
- [ ] **P1** Package passes Pest/Testbench, Larastan, coding-standard, migration, install, and compatibility CI jobs.
- [ ] **P1** Public contracts, config keys, commands, events, migrations, and upgrade notes are documented.
- [ ] **P1** Both optional bridges can be disabled or absent without changing stock-posting correctness.

---

## Phase 0 — Governance, Legacy Audit, and Greenfield Baseline

**References:** PRD-0, TSD-0, developer guide.  
**Depends on:** none.

### Design

- [x] **P0** Record PRD-0/TSD-0 as governance authority and define a change process for contradictions.
- [x] **P0** Freeze the target identity as Composer `elgibor-solution/laravel-inventory` and namespace `ESolution\Inventory` while translating placeholder `vendor` namespaces consistently.
- [x] **P0** Produce a legacy disposition map: retain conceptually, replace, or remove each current model, action, driver, service, config key, and migration.
- [x] **P0** Confirm fresh baseline v1 migrations; explicitly exclude migration of existing legacy production data.
- [x] **P0** Define package boundaries for Core, bridges, and nine independently installable vertical packages.
- [x] **P0** Define table-prefix ownership: `inv_*` for Core and a unique prefix for each vertical; external accounting/approval tables remain externally owned.
- [x] **P1** Define semantic versioning, external dependency re-audit triggers, supported Laravel/PHP/database matrix, and release branches.

### Legacy Removal/Replacement

> Decommission tasks are executed atomically in their owning Core/bridge phase.
> Removing legacy classes or migrations before replacements exist would leave the
> package unusable, so these tasks do not block the Phase 0 repository-foundation gate.

- [ ] **P0** Remove the internal `JournalPoster`/`JournalManager` design from the target architecture; replace it with optional Accounting/Null bridges.
- [ ] **P0** Replace the legacy combined `AverageDriver` with distinct Weighted Average and Moving Average drivers.
- [x] **P0** Replace legacy document/action orchestration with `PostingEngine`, registered Document Types, workflow transitions, and policy gates.
- [x] **P0** Replace the two legacy migrations with fresh Core baseline migrations matching TSD-0/TSD-1.
- [x] **P1** Preserve only verified reusable algorithms from legacy code; rewrite tests against the new contracts rather than legacy implementation details.

### CI and Repository Baseline

- [x] **P0** Add Pest, Orchestra Testbench, Larastan, coding-standard, and test database configuration.
- [ ] **P0** Add CI rule that fails if Core imports a vertical namespace or a vertical imports another vertical. *(Core-side guard complete; repeat the guard in each future vertical repository.)*
- [x] **P0** Add install tests for Core-only, bridges absent, and config-cached execution.
- [x] **P1** Add dependency audit and Composer validation jobs.

### Acceptance Criteria

- [ ] **ACG-01** Package dependency direction and cross-package FK rules pass static/schema inspection tests.
- [ ] **ACG-02** Every package follows the naming, provider, migration, and installation conventions.
- [ ] **ACG-03** Configuration-depth behavior is consistent across packages.
- [ ] **ACG-04** Core MVP tests pass before Phase 2 modules are enabled.
- [ ] **ACG-05** Core and every vertical work with Accounting and Approval disabled.
- [ ] **ACG-06** Cross-package installation and migration order succeeds.
- [ ] **ACG-07** Core and external approval statuses retain separate ownership.
- [ ] **ACG-08** All nine verticals can be installed independently and in supported combinations.

### Exit Gate

- [x] **P0 GATE-0** Architecture decisions, package boundaries, baseline CI, and legacy disposition are approved; no unresolved identity/schema decision remains.

---

## Phase 1 — Core Inventory

**References:** PRD-1 FR-01–FR-33, TSD-1, PRD-0/TSD-0.  
**Depends on:** GATE-0.

### Design and Public Contracts

- [x] **P0** Define `CostingDriver`, `MovementPolicy`, and `DocumentTypeRegistry` contracts with stable signatures.
- [ ] **P0** Define repository contracts for Items, Ledger, and Reservations; Ledger exposes no update/delete operation.
- [x] **P0** Define DTOs for document headers/lines, posting context, costing results, and reversal requests.
- [x] **P0** Define `PostingEngine`, `WorkflowEngine`, `PolicyEngine`, `ConfigurationDepthResolver`, `ReservationService`, and `StockCardManager` responsibilities.
- [x] **P0** Define standard Document Types and their allowed transition maps.
- [ ] **P1** Define public facade methods and extension registration for Document Types, costing drivers, movement policies, events, and hooks.

### Schema — Fresh Baseline

- [ ] **P0** Create organization and storage trees with valid parent/child level constraints (FR-01–FR-03).
- [x] **P0** Create master-data tables for categories, groups, UoMs/conversions, brands, items/types, variants, reasons, and inventory calendars (FR-04–FR-06).
- [x] **P0** Create batches, serials, and certificates with tracking indexes and ownership constraints (FR-25–FR-26).
- [x] **P0** Create documents and lines with workflow status, approval status, scoped source/party references, bonus quantity, warehouse/location, batch, serial, and metadata (FR-12–FR-19).
- [x] **P0** Add `posted_at`, reversal linkage, and a posting-attempt/idempotent completion marker to protect approved-document resume and retries.
- [x] **P0 PATCH-IDEMPOTENCY** Replace global `external_id` uniqueness with `(organization_id, source_type, external_id)`; store a canonical payload hash and reject same-key/different-payload retries.
- [x] **P0** Create append-only stock ledger and cost layers with document-line traceability and `batch_id` available in Core baseline.
- [x] **P0 PATCH-SCOPE** Create scoped Stock Cards using `item_id`, `scope_type`, `scope_id`, `as_of`; enforce a composite unique key.
- [x] **P0 PATCH-RESERVATION** Create Reservations with `reserved_qty`, `consumed_qty`, `released_qty`, status, source reference, warehouse, and timestamps; derive remaining quantity from these columns.
- [x] **P0** Create policy overrides and immutable audit trails.
- [ ] **P1** Add explicit named foreign keys, indexes for posting/costing/reporting queries, and database portability tests.

### Organization, Storage, and Configuration

- [x] **P0** Implement configurable organization/storage levels without schema branching.
- [ ] **P0** Validate parent-enabled-before-child and mandatory warehouse/rack minimums during install/config validation. *(Mandatory minimum and costing config validation are complete; parent/child runtime validation remains.)*
- [ ] **P0** Ensure disabling a level affects new transactions only and never deletes historical data.
- [ ] **P1** Implement sector preset merge order with explicit project overrides winning.

### Documents, Workflow, and Posting

- [x] **P0** Implement document creation and line validation inside a transaction.
- [x] **P0** Implement allowed state transitions and immutable status audit entries.
- [ ] **P0** Implement posting order: validate → approval gate → costing → movement ledger → accounting trigger → Stock Card → Posted.
- [ ] **P0** Implement reversal as a new linked document; reject edits/deletes to posted ledger effects.
- [x] **Tenda prerequisite scope** Implement atomic untracked standard FIFO warehouse transfer and exact-layer linked reversal with bridges disabled; verify rollback, retry, value conservation and concurrent requests on MariaDB with a table prefix. See `docs/TRANSFER_REVERSAL.md` for exclusions. This does not close the general reversal or release gate.
- [x] **P0 PATCH-RESUME** Implement `ResumeApprovedDocument` service/job that locks the document, checks approval/posting markers, resumes at costing exactly once, and safely no-ops on duplicate delivery.
- [ ] **P0 PATCH-RESUME** Add unique posting-completion protection and concurrency tests for two workers resuming the same approved document.
- [ ] **P1** Dispatch domain events only after commit and register deterministic veto/transition hooks.

### Costing and Stock Control

- [x] **P0** Implement FIFO with ordered row locking and bounded retry on lock contention.
- [ ] **P0** Implement true Weighted Average as period/batch aggregate behavior defined by configuration.
- [ ] **P0** Implement Moving Average recalculated after each receipt; do not alias it to Weighted Average.
- [ ] **P0** Resolve costing by item+scope with item/location override precedence.
- [x] **P0 PATCH-NEGATIVE** Define `last_known_cost`; reject negative-stock issue if no prior valid cost exists, even when quantity policy allows negative stock.
- [x] **P0 PATCH-NEGATIVE** Represent negative consumption explicitly and settle/revalue it on the next receipt using new adjustment records without mutating posted ledger rows.
- [ ] **P0** Implement available quantity as on-hand minus active reservation balance minus locks.
- [ ] **P0** Implement negative-stock block/allow, inventory locks, and freeze behavior with scope-level concurrency protection.
- [ ] **P1** Implement Standard Cost, Specific Identification, and Actual Cost after the three MVP drivers are stable.

### Tracking and Domain Integrity

- [x] **P0 PATCH-TRACKING** Validate `batch.item_id` and `serial.item_id` equal the document-line item.
- [x] **P0 PATCH-TRACKING** Validate serial warehouse/location, availability status, one-unit semantics, and serial count equals tracked quantity.
- [ ] **P0 PATCH-TRACKING** Validate batch expiry, recall state, and certificate policy before posting.
- [ ] **P0** Implement certificate attachment only to Batch/Serial and enforce required certificate type/validity.
- [ ] **P1** Implement deterministic tracking validation errors suitable for API consumers.

### Reservation and Reporting Core

- [x] **P0** Implement transactional `reserve`, `release`, and repeated partial `consume` operations with row locks.
- [x] **P0 PATCH-RESERVATION** Reject over-consumption/over-release and require a fulfillment idempotency key for each consume operation.
- [x] **P0 PATCH-RESERVATION** Link reservation consumption to Goods Issue/document line and support exact audit/reporting without inferring state from unrelated ledger rows.
- [x] **P0** Ensure Reservation operations never write ledger/cost layers or invoke either bridge.
- [ ] **P0** Implement scoped Ledger, Stock Card, valuation, movement, and available-stock query services.
- [ ] **P1** Implement reorder notification deduplication, scheduled checks, and reconciliation commands.

### Tests

- [ ] **P0** Unit-test all costing formulas, policy precedence, workflow transitions, depth resolution, and tracking validators.
- [ ] **P0** Feature-test every standard Receipt, Issue, Transfer, Adjustment, Count, reversal, and non-stock/service line.
- [ ] **P0** Concurrency-test FIFO layer consumption, negative stock, reservation consumption, Stock Card updates, and approval resume.
- [ ] **P0** Constraint-test scoped idempotency, Stock Card uniqueness, reservation totals, batch/serial ownership, and ledger immutability.
- [ ] **P1** Test events after commit, config cache, supported databases, and Core install without either external package.

### Acceptance Criteria

- [ ] **AC-01** Core receipt/issue works under FIFO, Weighted Average, and Moving Average.
- [ ] **AC-02** Organization and storage depth can be configured while mandatory minimums remain valid.
- [ ] **AC-03** Disabling levels preserves historical data.
- [ ] **AC-04** Posted ledger effects are immutable and reversible only by reversing document.
- [x] **AC-05** Document creation is idempotent.
- [x] **AC-06** Party/source polymorphic references work without external FKs.
- [x] **AC-07** Non-stock and service lines do not affect inventory ledger.
- [x] **AC-08** Costing scope changes grouping without schema changes.
- [ ] **AC-09** Batch/serial/expiry tracking is enforced only where configured.
- [ ] **AC-10** Certificate requirements block invalid posting.
- [x] **AC-11** Purchase bonus quantity blends cost correctly.
- [x] **AC-12** Sale bonus quantity consumes total quantity and remains separately reportable.
- [ ] **AC-13** Certificates cannot attach without valid tracking identity.
- [ ] **AC-14** Reorder notification is deduplicated and creates no purchase document.
- [x] **AC-15** Negative-stock block/allow behavior and cost treatment are correct.
- [ ] **AC-16** Locks/freeze prevent prohibited movements at the correct scope.
- [ ] **AC-17** Custom contracts and Document Types register without Core patches.
- [x] **AC-18** Posting proceeds when approval is disabled/not required.
- [x] **AC-19** Posting proceeds when accounting is disabled.
- [x] **AC-20** Accounting failure rolls back stock posting.
- [x] **AC-21** Core alone owns `Document.status`.
- [ ] **AC-22** Domain events are emitted at the documented lifecycle point.
- [ ] **AC-23** Reservation operations update availability without ledger/bridge effects.

### Open Decisions

- [ ] **P0 BLOCKER** Confirm approval rejection target per Document Type; default `rejected → draft` is not GA-ready without product sign-off.
- [ ] **P1** Resolve destination polymorphic reference requirements separately from destination warehouse.
- [ ] **P1** Lock down `scope_type`/`scope_id` validation and type mapping at service and database boundaries.
- [ ] **P2 DEFERRED** Specify Bundle/Kit component derivation before implementing FR-07.

### Exit Gate

- [ ] **P0 GATE-1** AC-01–AC-23 pass; P0 patches pass concurrency/constraint tests; Core works standalone.

---

## Phase 2 — Accounting Bridge

**References:** PRD-2 FR2-01–FR2-09, AC2-01–AC2-09; TSD-2.  
**Depends on:** GATE-1.

### Design, Implementation, and Tests

- [x] **P0** Implement conditional real/Null Accounting Bridge binding; external package remains optional.
- [x] **P0** Remove all internal journal migrations, models, account mapping, and posting logic from the target Core.
- [x] **P0** Map Core Document Types to verified external `service_code` values; fail closed for missing mappings.
- [x] **P0** Forward only inventory-computed quantity/cost plus caller-supplied financial fields; do not implement GL logic.
- [x] **P0** Propagate accounting exceptions so the posting transaction rolls back.
- [x] **P0** Implement linked accounting reversal and prevent double reversal.
- [x] **P1** Pass tenant identity through without coupling it to Core organization hierarchy.
- [x] **P1** Re-audit external package signatures/config whenever its major version changes.
- [x] **P0** Contract-test Null, successful post, mapping failure, locked period, rollback, and reversal paths. *(Stub contract is green; real external-package fixture remains required by GATE-2.)*

### Acceptance Criteria

- [x] **AC2-01** Bridge is optional and Null bridge makes no external call.
- [x] **AC2-02** Verified service mapping is used.
- [x] **AC2-03** Correct inventory valuation payload is forwarded.
- [x] **AC2-04** Caller-supplied additional lines are forwarded without Core accounting logic.
- [x] **AC2-05** Tenant pass-through follows project resolution.
- [x] **AC2-06** External exception rolls back posting.
- [x] **AC2-07** Reversal links correctly and cannot be duplicated.
- [x] **AC2-08** Missing mapping fails closed with a diagnostic error.
- [x] **AC2-09** Core contains no internal accounting schema/engine.

### Exit Gate

- [ ] **P0 GATE-2** AC2-01–AC2-09 pass against stubs and the verified external package test fixture. *(Stub suite passes; blocked on installing and testing the real optional package fixture.)*

---

## Phase 3 — Approval Bridge

**References:** PRD-3 FR3-01–FR3-11, AC3-01–AC3-11; TSD-3.  
**Depends on:** GATE-1; integrates with PATCH-RESUME.

### Design, Implementation, and Tests

- [x] **P0** Implement conditional real/Null Approval Bridge binding and exact verified call signatures.
- [x] **P0** Maintain dual-column ownership: Core owns `status`; external package owns `approval_status`.
- [x] **P0** Submit exactly once and pause the posting pipeline at Waiting Approval.
- [x] **P0** Route approved status to `ResumeApprovedDocument`; do not merely write `Approved` and stop.
- [ ] **P0** Apply configured rejection target per Document Type after blocker decision is approved. *(Mechanism and tests are complete; product approval in BLOCK-01 remains open.)*
- [x] **P0** Do not automatically equate external cancelled status with Core cancellation.
- [x] **P0** Validate IdentityResolver, service-auth behavior, status-field config, matching rules, and published workflows.
- [x] **P1** Provide `inventory:approval:validate` and clear diagnostics for runtime prerequisites.
- [ ] **P0** Test duplicate submit, duplicate approval events, concurrent resume, rejection, cancellation, missing identity, and unpublished workflows. *(All stub paths pass; real-package parallel concurrency remains.)*

### Acceptance Criteria

- [x] **AC3-01** Null bridge posts without pause/call.
- [x] **AC3-02** No matching rule posts immediately.
- [x] **AC3-03** Matching rule submits once and pauses.
- [x] **AC3-04** Retry does not create duplicate ApprovalInstance.
- [x] **AC3-05** Approval resumes and completes posting exactly once.
- [x] **AC3-06** Rejection applies configured Core target.
- [x] **AC3-07** External cancellation does not overwrite Core status automatically.
- [x] **AC3-08** Status-column ownership remains isolated.
- [x] **AC3-09** Unpublished workflow produces a diagnosable error.
- [x] **AC3-10** Identity/service-auth prerequisites are validated.
- [x] **AC3-11** Bridge behavior survives configured tenant resolution.

### Exit Gate

- [ ] **P0 GATE-3** AC3-01–AC3-11 and approval-resume concurrency tests pass. *(Stub suite passes; blocked on BLOCK-01, real optional-package fixture, and parallel database concurrency test.)*

---

## Phase 4 — Reservation / Sales-Purchasing Integration

**References:** PRD-4 FR4-01–FR4-08, AC4-01–AC4-07; TSD-4.  
**Depends on:** GATE-1.

### Design, Implementation, and Tests

- [x] **P0** Publish reference Sales integration for reserve, cancel/release, partial fulfillment, and walk-in sale.
- [x] **P0** Wrap Goods Issue posting and reservation consumption in the same transaction, including deferred consumption during Approval pause/resume.
- [x] **P0** Publish Purchasing integration that bypasses Reservation and posts Goods Receipt directly.
- [x] **P0** Use fulfillment idempotency keys and exact consumption linkage added by PATCH-RESERVATION.
- [x] **P0** Test retries, partial shipments, over-consumption, failed Goods Issue/accounting rollback, release, and zero bridge calls.

### Acceptance Criteria

- [x] **AC4-01** Sales confirmation reserves and decreases availability only.
- [x] **AC4-02** Fulfillment posts issue and consumes matching reservation atomically.
- [x] **AC4-03** Release restores availability without ledger effects.
- [x] **AC4-04** Reservation operations never invoke bridges.
- [x] **AC4-05** Repeated partial consumption closes only when exhausted.
- [x] **AC4-06** Walk-in issue works without prior reservation.
- [x] **AC4-07** Purchasing receipt requires no reservation.

### Exit Gate

- [ ] **P0 GATE-4** AC4-01–AC4-07 and retry/concurrency tests pass. *(All functional AC and sequential retry tests pass; true parallel reservation enforcement still requires the MySQL/PostgreSQL multi-connection harness.)*

---

## Phase 5 — Retail Vertical

**References:** PRD-5 FR5-01–FR5-10, AC5-01–AC5-08; TSD-5.  
**Depends on:** GATE-1, GATE-4.

### Design, Schema, and Implementation

- [x] **P1** Package Retail independently with no sibling vertical dependency.
- [x] **P1** Model Product Family, variant axes/options, and stock-bearing variants as distinct Core Items.
- [x] **P1** Implement variant matrix generation with deterministic SKU uniqueness and bulk-safe behavior.
- [x] **P1** Implement Consignment Movement Policy, item/location resolution, ownership-neutral receipt, sale tracking, and settlement records.
- [x] **P1** Keep settlement accounting project-owned; do not call Accounting Bridge from Retail settlement logic.
- [x] **P1** Document POS and E-Commerce reservation/fulfillment reference flows.

### Tests and Acceptance Criteria

- [x] **AC5-01** Generated variants are independently stock tracked.
- [x] **AC5-02** Sibling variant availability is isolated.
- [x] **AC5-03** Consignment receipt updates physical stock.
- [x] **AC5-04** Consignment receipt does not assert owned valuation.
- [x] **AC5-05** Consignment sale/settlement traceability is correct.
- [x] **AC5-06** Item/location policy override precedence works.
- [x] **AC5-07** POS flow uses ordinary Core posting.
- [x] **AC5-08** E-Commerce flow uses Core Reservation unchanged.

### Exit Gate

- [ ] **P1 GATE-5** AC5-01–AC5-08 pass with both bridges disabled. *(Retail AC suite passes with Null bridges; formal gate remains blocked by GATE-4 concurrency and PRD-5 AC5-08's real sibling-vertical coexistence matrix.)*

---

## Phase 6 — WMS Vertical

**References:** PRD-6 FR6-01–FR6-11, AC6-01–AC6-07; TSD-6.  
**Depends on:** GATE-1.

### Design, Schema, and Implementation

- [x] **P1** Package WMS independently and implement its vertical-owned tables/migrations.
- [x] **P1** Finalize `PutAwayStrategy` and `PickingStrategy` promotion/ownership before coding dependent strategies.
- [x] **P1** Implement Fixed/Dynamic/Random/Dedicated/Nearest/Empty-Bin strategies and FIFO/FEFO picking suggestions.
- [x] **P1** Implement Task, Wave, Pallet/LPN orchestration through workflow hooks without replacing Core posting.
- [x] **P1** Implement internal replenishment scheduler and Cross Docking Movement Policy.
- [x] **P2** Publish TMS integration pattern without adding a hard dependency.

### Tests and Acceptance Criteria

- [x] **AC6-01** Put-away strategies produce valid deterministic locations.
- [x] **AC6-02** Picking strategies produce valid deterministic suggestions.
- [x] **AC6-03** Tasks and waves reflect document transitions without duplicate side effects.
- [x] **AC6-04** Pallet/LPN quantities and locations remain consistent.
- [x] **AC6-05** Replenishment creates only intended internal work.
- [x] **AC6-06** Cross docking changes routing without corrupting costing/ledger.
- [x] **AC6-07** WMS has no sibling vertical dependency.

### Exit Gate

- [x] **P1 GATE-6** AC6-01–AC6-07 and hook-idempotency tests pass.

---

## Phase 7 — Manufacturing Vertical

**References:** PRD-7 FR7-01–FR7-11, AC7-01–AC7-08; TSD-7.  
**Depends on:** GATE-1.

### Design, Schema, and Implementation

- [x] **P1** Implement immutable versioned BOMs and validate allowed component/output Item Types.
- [x] **P1** Implement Production Order orchestration using ordinary Production Consumption/Receipt actions.
- [x] **P1** Roll actual component CostResult into output receipt unit cost.
- [x] **P1** Support MTS/MTO/BTO/ATO source linkage and chained WIP without a new Movement Policy.
- [x] **P1** Record scrap/yield variance without mutating posted movements.
- [x] **P0 BLOCKER** Keep Manufacturing accounting disabled/fail-closed until verified service codes exist. *(Guard rejects completion before stock effects whenever Core/Manufacturing accounting is enabled or a non-Null bridge is resolved; verified service codes remain intentionally unavailable.)*

### Tests and Acceptance Criteria

- [x] **AC7-01** BOM version used by production remains immutable/traceable.
- [x] **AC7-02** Consumption reduces valid component stock.
- [x] **AC7-03** Receipt creates output stock from actual rolled-up cost.
- [x] **AC7-04** Production pair is transactionally consistent.
- [x] **AC7-05** MTO/BTO/ATO source references remain traceable.
- [x] **AC7-06** Multi-stage WIP chaining works.
- [x] **AC7-07** Variance records are correct and immutable.
- [x] **AC7-08** No sibling dependency or duplicate Core posting logic exists.

### Exit Gate

- [x] **P1 GATE-7** AC7-01–AC7-08 pass; accounting remains explicitly fail-closed until blocker resolution.

---

## Phase 8 — Healthcare Vertical

**References:** PRD-8 FR8-01–FR8-08, AC8-01–AC8-09; TSD-8.  
**Depends on:** GATE-1; Core owns `inv_cost_layers.batch_id`.

### Design, Schema, and Implementation

- [x] **P1** Publish Healthcare preset for mandatory batch, FEFO, COA, and expiry behavior.
- [x] **P1 PATCH-FEFO** Implement deterministic FEFO: non-null expiry first, `expires_at ASC`, `received_at ASC`, `id ASC`.
- [x] **P1 PATCH-FEFO** Exclude expired/recalled batches; place null expiry last or reject it when Healthcare policy requires expiry.
- [x] **P1** Implement expiry enforcement gate on Goods Issue while allowing controlled expired receipt for disposal tracking.
- [x] **P1** Implement Recall records, batch-specific posting veto, and forward trace.
- [x] **P1** Keep FEFO independent from WMS physical picking.

### Tests and Acceptance Criteria

- [x] **AC8-01** Healthcare preset merges correctly.
- [x] **AC8-02** FEFO consumes earliest valid expiry regardless of receipt order.
- [x] **AC8-03** FEFO works with WMS absent.
- [x] **AC8-04** Expired Goods Issue is blocked.
- [x] **AC8-05** Controlled expired receipt remains traceable.
- [x] **AC8-06** Recall blocks only the recalled batch.
- [x] **AC8-07** Recall forward trace identifies affected outbound documents.
- [x] **AC8-08** COA policy is enforced.
- [x] **AC8-09** Healthcare has no sibling vertical dependency.

### Exit Gate

- [x] **P1 GATE-8** AC8-01–AC8-09 and deterministic FEFO tests pass.

---

## Phase 9 — Food Vertical

**References:** PRD-9 FR9-01–FR9-12, AC9-01–AC9-09; TSD-9.  
**Depends on:** GATE-1.

### Design, Schema, and Implementation

- [x] **P1** Implement immutable versioned Recipes and component Item Type validation.
- [x] **P1** Register `recipe_consumption` and `recipe_receipt` using ordinary Core actions.
- [x] **P1** Implement Made-to-Order transition hook with duplicate-trigger protection.
- [x] **P1** Implement Made-to-Stock RecipeBatch pairing consumption and receipt atomically with actual cost roll-up.
- [x] **P1** Publish Food preset with Halal certificate policy.
- [x] **P1** Do not import Manufacturing or Healthcare; document optional project-provided FEFO.
- [x] **P0 BLOCKER** Keep Food accounting disabled/fail-closed until verified service codes exist.

### Tests and Acceptance Criteria

- [x] **AC9-01** Recipe versions remain immutable/traceable.
- [x] **AC9-02** Recipe Document Types post through Core correctly.
- [x] **AC9-03** Made-to-Order trigger runs exactly once.
- [x] **AC9-04** RecipeBatch pairs consumption/receipt consistently.
- [x] **AC9-05** Output cost uses actual component cost.
- [x] **AC9-06** Food preset and Halal policy merge correctly.
- [x] **AC9-07** Invalid component types are rejected.
- [x] **AC9-08** Accounting gap fails closed.
- [x] **AC9-09** Food has no sibling vertical dependency.

### Exit Gate

- [x] **P1 GATE-9** AC9-01–AC9-09 pass; accounting blocker remains explicit.

---

## Phase 10 — Asset Vertical

**References:** PRD-10 FR10-01–FR10-11, AC10-01–AC10-09; TSD-10.  
**Depends on:** GATE-1, GATE-4.

### Design, Schema, and Implementation

- [x] **P1** Publish Asset preset with serial tracking.
- [x] **P1** Implement Check-Out records wrapping one Core Reservation; check-in releases it without ledger effects.
- [x] **P0 PATCH-CHECKOUT** Move availability/double-check checks inside one transaction and lock the Serial row before creating a checkout.
- [x] **P0 PATCH-CHECKOUT** Enforce one active allocation per serial using a portable unique active-allocation table/key, not `exists()` alone.
- [x] **P1** Keep overdue status derived at read time and scheduled detection notification-only.
- [x] **P1** Keep Check-Out record authoritative for loan status; document Core serial-status limitation.

### Tests and Acceptance Criteria

- [x] **AC10-01** Asset preset enables required tracking.
- [x] **AC10-02** Check-out reserves exactly one valid serialized Asset.
- [x] **AC10-03** Check-in releases reservation without stock movement.
- [x] **AC10-04** On-hand stays unchanged throughout loan.
- [x] **AC10-05** Invalid Item Type/serial is rejected.
- [x] **AC10-06** Concurrent checkout cannot double-allocate a serial.
- [x] **AC10-07** Overdue detection is derived and notification-only.
- [x] **AC10-08** Asset adds no Document Type/MovementPolicy/CostingDriver.
- [x] **AC10-09** Asset has no sibling vertical dependency and works with bridges disabled.

### Exit Gate

- [x] **P0 GATE-10** AC10-01–AC10-09 and double-checkout concurrency tests pass.

---

## Phase 11 — Project Vertical

**References:** PRD-11 FR11-01–FR11-11, AC11-01–AC11-09; TSD-11.  
**Depends on:** GATE-1, GATE-4.

### Design, Schema, and Implementation

- [x] **P1** Implement ProjectAllocation with polymorphic Project reference, Core Site/warehouse, Item, and one Reservation.
- [x] **P1** Implement replenishment as a new allocation/reservation, never reservation mutation.
- [x] **P1** Implement reallocation as atomic source release plus destination allocation.
- [x] **P1** Implement partial material draw via existing Goods Issue/Transfer and exact reservation-consumption linkage.
- [x] **P1** Implement reporting from stored reservation quantities/consumption links rather than approximate unrelated ledger inference.
- [x] **P1** Publish no sector preset and add no new movement/costing/document contract.

### Tests and Acceptance Criteria

- [x] **AC11-01** Allocation creates one matching reservation.
- [x] **AC11-02** Site uses Core hierarchy without new organization level.
- [x] **AC11-03** Replenishment creates a separate allocation.
- [x] **AC11-04** Reallocation is explicit, atomic, and balance-safe.
- [x] **AC11-05** Partial draw consumes exact remaining reservation.
- [x] **AC11-06** Reporting totals allocated/consumed/remaining exactly.
- [x] **AC11-07** No new stock movement/costing behavior is introduced.
- [x] **AC11-08** No sector preset is published.
- [x] **AC11-09** Project has no sibling dependency and works with bridges disabled.

### Exit Gate

- [x] **P1 GATE-11** AC11-01–AC11-09 and allocation concurrency tests pass.

---

## Phase 12 — Automotive Vertical

**References:** PRD-12 FR12-01–FR12-10, AC12-01–AC12-07; TSD-12.  
**Depends on:** GATE-1.

### Design, Schema, and Implementation

- [x] **P1** Publish Automotive preset for serial and Compliance certificate.
- [x] **P1** Register `work_order_parts_issue` as a naming-only specialization of ordinary Goods Issue.
- [x] **P1** Store Work Order/vehicle references polymorphically; do not own their schemas.
- [x] **P1** Implement part usage reporting by Work Order/vehicle/item/serial.
- [x] **P1** Add indexes only after validating report query plans.
- [x] **P0 BLOCKER** Verify Automotive service-code coverage before enabling Accounting Bridge; otherwise fail closed.

### Tests and Acceptance Criteria

- [x] **AC12-01** Automotive preset merges correctly.
- [x] **AC12-02** Work-order issue reuses ordinary Goods Issue behavior.
- [x] **AC12-03** No duplicate movement/costing implementation exists.
- [x] **AC12-04** Work Order/vehicle references remain external and polymorphic.
- [x] **AC12-05** Part usage reports trace item/serial to source work.
- [x] **AC12-06** Accounting enablement is blocked without verified mapping.
- [x] **AC12-07** Automotive has no sibling dependency and works with bridges disabled.

### Exit Gate

- [x] **P1 GATE-12** AC12-01–AC12-07 pass; accounting decision is verified or explicitly fail-closed.

---

## Phase 13 — Library Vertical

**References:** PRD-13 FR13-01–FR13-12, AC13-01–AC13-10; TSD-13.  
**Depends on:** GATE-1, GATE-4; reuse the active-serial allocation invariant, not Asset code.

### Design, Schema, and Implementation

- [x] **P1** Publish Library preset with per-copy serial tracking.
- [x] **P1** Implement Circulation records wrapping one Core Reservation per copy/patron loan.
- [x] **P0 PATCH-CHECKOUT** Lock Serial and enforce one active allocation per copy at database level during checkout.
- [x] **P1** Implement check-in/release, renewals, derived overdue status, and fines as non-ledger domain records where specified.
- [x] **P1** Implement Hold queue without reserving stock while waiting.
- [x] **P0** Implement atomic `fulfillNextHold` with queue-row locks, deterministic ordering, expiry handling, and duplicate-fulfillment protection.
- [x] **P1** Share no runtime code/dependency with Asset; duplicate only the independently specified composition pattern.

### Tests and Acceptance Criteria

- [x] **AC13-01** Library preset enables per-copy tracking.
- [x] **AC13-02** Checkout creates circulation and reservation for one copy.
- [x] **AC13-03** Check-in releases availability without ledger effects.
- [x] **AC13-04** Concurrent checkout cannot loan one copy twice.
- [x] **AC13-05** Waiting Holds do not reduce Core availability.
- [x] **AC13-06** Hold queue ordering and ready transition are deterministic.
- [x] **AC13-07** Expired ready Hold advances the queue exactly once.
- [x] **AC13-08** Concurrent check-in/expiry cannot double-fulfill a Hold.
- [x] **AC13-09** Overdue/fine behavior remains outside stock ledger.
- [x] **AC13-10** Library has no Asset/sibling dependency and works with bridges disabled.

### Exit Gate

- [x] **P0 GATE-13** AC13-01–AC13-10 and circulation/hold concurrency tests pass.

Validation: 9 Library tests / 127 assertions, including independent PHP workers sharing SQLite.
Production MySQL/PostgreSQL row-lock behavior still requires deployment-environment verification.

---

## Phase 14 — Ecosystem Integration, Documentation, and Release

**References:** PRD-0/TSD-0 governance and ACG-01–ACG-08.  
**Depends on:** GATE-1; release scope determines required vertical gates.

### Integration Matrix

Phase 14 local evidence: `Phase14EcosystemTest` covers 17 SQLite installation/
coexistence smoke cases, not combined domain workflows or real external bridges.
`Phase14CatalogTest` covers the complete catalog's dependency and migration/table
name collisions. See `docs/ECOSYSTEM_RELEASE.md` and `docs/RELEASE_NOTES.md` for
remaining release evidence; unchecked requirements below are intentionally open.

- [x] **P0** Test Core alone with both bridges absent.
- [ ] **P0** Test Core with Accounting only, Approval only, and both enabled.
- [ ] **P1** Test each vertical independently against Core.
- [ ] **P1** Test representative combinations: Retail+Manufacturing, Healthcare+Retail/Consignment, WMS+Healthcare, Food+WMS, Asset+Project, Library+Retail.
- [ ] **P0** Verify installation order, migration order, config publishing, config cache, uninstall behavior, and no table-prefix collisions.
- [ ] **P0** Verify no package writes tables owned by another package; Core baseline owns `inv_cost_layers.batch_id`.

### Quality and Performance

- [ ] **P0** Run full unit/feature/contract/concurrency/constraint test suites on every supported database.
- [ ] **P0** Verify posting rollback across costing, ledger, accounting failure, approval pause/resume, and Stock Card updates.
- [ ] **P1** Benchmark hot ledger, cost-layer, Stock Card, reservation, FEFO, and reporting queries against target data volumes.
- [ ] **P1** Verify queue retries, after-commit events, scheduler overlap locks, and command idempotency.
- [ ] **P1** Run security review for multi-organization scoping, polymorphic references, mass assignment, authorization extension points, and file attachments.

### Documentation and Release

- [x] **P0** Rewrite README for the greenfield architecture and remove legacy internal-journal claims/examples.
- [ ] **P1** Document installation, package combinations, public API, config, commands, events, contracts, database ownership, and troubleshooting.
- [x] **P1** Publish migration/schema reference and generated API documentation.
- [x] **P1** Record known limitations and fail-closed modules explicitly in release notes.
- [x] **P0** Block release while any P0 task, MVP AC, required gate, or blocker for an enabled feature is open. (`composer release:preflight`; tag CI rejects open blockers, no publishing automation.)
- [ ] **P1** Tag release only after clean install from an empty database and the complete release matrix succeeds.

### Final Acceptance Gates

- [ ] **ACG-01** One-way dependency/static boundary checks pass across the complete catalog.
- [ ] **ACG-02** Naming/install/migration conventions pass across the complete catalog.
- [ ] **ACG-03** Configuration-depth behavior remains backward-safe.
- [ ] **ACG-04** Core gate is green before optional package release.
- [ ] **ACG-05** All enabled modules operate with bridges disabled.
- [ ] **ACG-06** Ecosystem installation/migration sequence is reproducible.
- [ ] **ACG-07** External status/data ownership boundaries remain intact.
- [ ] **ACG-08** Every vertical is independently installable and combinable.
- [ ] **P0 RELEASE-GATE** All required gates and acceptance criteria are green; no enabled feature has an unresolved blocker.

---

## Deferred / Explicitly Out of Scope for Baseline v1

- [ ] **P2** Legacy production-data migration utility; baseline v1 supports fresh installation only.
- [ ] **P2** Bundle/Kit stock derivation until its business rules are approved.
- [ ] **P2** Full General Ledger engine; Accounting Bridge remains a trigger into the external package.
- [ ] **P2** Built-in UI/admin/dashboard.
- [ ] **P2** Advanced analytics/BI package.
- [ ] **P2** Dedicated Sales/Purchasing domain package.
- [ ] **P2** Generic outbound webhook delivery system; consumers may listen to domain events.
- [ ] **P2** Food-owned FEFO implementation; use project-provided driver until promoted to Core by governance.
- [ ] **P2** Core serial status extension for temporary loan state unless separately approved.

## Blocker Register

- [ ] **BLOCK-01 / P0** Approve per-Document-Type rejection target before Approval Bridge GA.
- [ ] **BLOCK-02 / P0** Verify/add external accounting service codes before enabling Manufacturing.
- [ ] **BLOCK-03 / P0** Verify/add external accounting service codes before enabling Food.
- [ ] **BLOCK-04 / P0** Verify Automotive accounting service-code coverage before enabling its bridge path.
- [ ] **BLOCK-05 / P1** Decide whether destination polymorphic reference is required beyond destination warehouse.
- [ ] **BLOCK-06 / P1** Finalize runtime/database validation for polymorphic costing `scope_type`/`scope_id`.
- [ ] **BLOCK-07 / P2** Approve Bundle/Kit derivation model before FR-07 implementation.
