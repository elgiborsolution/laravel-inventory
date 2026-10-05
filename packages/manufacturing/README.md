# Laravel Inventory Manufacturing

`elgibor-solution/laravel-inventory-manufacturing` is an independently
installable vertical that depends only on Inventory Core. It owns all `invm_*`
tables and does not implement stock posting or costing logic.

## BOM and production

BOM versions are editable only while draft. Activation makes the version and
its components immutable; a Production Order always retains the exact version
it used. Both components and outputs must be active Core Items with an allowed
stock-bearing Item Type.

`ProductionOrderService::complete()` runs in one database transaction. It:

1. posts ordinary `production_consumption` through Core;
2. reads the actual outbound Stock Ledger cost;
3. posts ordinary `production_receipt` at the rolled unit cost;
4. records immutable scrap/usage and yield variances; and
5. marks the Production Order completed with both Core document links.

MTS, MTO, BTO, and ATO are source-link modes, not Movement Policies. MTO/BTO/ATO
require string-safe business source references. Multi-stage WIP uses a normal
stock Item as one order's output and the child order's BOM component, linked by
`parent_order_id`.

## Accounting blocker

Manufacturing accounting is intentionally fail-closed. Completion is allowed
only while Core resolves `NullAccountingBridge`, Core accounting is disabled,
and `inventory-manufacturing.accounting.enabled` is false. No Manufacturing
service codes are guessed. Enabling either setting before verified service
codes are published rejects the operation before any stock effect.
