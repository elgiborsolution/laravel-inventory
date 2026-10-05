# Laravel Inventory Asset

`elgibor-solution/laravel-inventory-asset` is an independent vertical that
depends only on Inventory Core and owns all `inva_*` tables.

`AssetPreset` enables serialized receipt/issue tracking and marks an Item as
checkout-managed. Check-out locks the Core Serial first, performs availability
and duplicate checks in the same transaction, creates exactly one Core
Reservation, and inserts a portable unique active-allocation row. Check-in
releases that Reservation and deletes the active-allocation row. Neither action
posts a stock document or changes on-hand quantity.

Loan state is authoritative in `inva_checkouts`. Core's Serial status remains
`in_stock` because Core currently has no non-ledger `on_loan` serial state; the
unique active allocation and checkout status are the availability authority.

Overdue is derived from an active checkout's `due_at` at read time. The
`inventory-assets:detect-overdue` command invokes the replaceable
`OverdueNotifier` only and never mutates checkout, reservation, serial, or stock
state. Projects may schedule this command using Laravel's normal scheduler.

The Asset package intentionally registers no Document Type, MovementPolicy, or
CostingDriver and works with accounting and approval bridges disabled.
