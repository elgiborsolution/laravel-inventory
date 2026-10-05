# Inventory Library

Independent package depending only on Inventory Core. Register
LibraryServiceProvider, run migrations, and optionally publish
`inventory-library-config`. It owns only `invl_*` tables and imports no
Asset or other vertical code.

Apply LibraryPreset to an active stock Item, create one Core Serial per copy,
and receive each copy through Core. Core serial lifecycle remains unchanged:
Circulation and the active-allocation table are authoritative for Library loans.
Inventory movement, disposal, and serial relocation while allocated must be
controlled by the host application. Cross-vertical allocation is not coordinated.

CirculationService exposes:

- hold(number, itemId, warehouseId, patronType, patronId, expiresAt = null)
- fulfillNextHold(serialId)
- checkout(number, serialId, patronType, patronId, dueAt)
- checkin(loanId)
- renew(loanId, renewalNo, dueAt)
- expireReadyHolds()
- recordFine(loanId, number, amountMinor, currency, reason)

Patron references are external type/ID strings; no patron table is owned.
Waiting Holds create no reservation. Queue order is created_at then ID.
Ready Holds reserve one copy for ready_hours (default 48), capped at the Hold's
own expiry. Pickup by the same patron reuses the ready Reservation and records
the Hold link on the Circulation. Another patron cannot take that ready copy.
Check-in releases the loan Reservation and attempts to ready the next Hold.
Schedule expireReadyHolds() in the host to release expired ready allocations.
Waiting expiry is evaluated when that queue is processed.

All queue/copy operations lock Item then Serial, followed by domain rows in a
transaction. Serial is the active-allocation primary key; hold_id,
circulation_id and reservation_id also have unique constraints. Retries do not
create another loan, ready allocation, renewal or fine. Reservation quantities
are always one; on-hand and Stock Ledger remain unchanged during circulation.

Renewals extend due_at, keep an audit row, and are rejected while a live Hold
queue exists. Overdue is the derived is_overdue property on Circulation.
Fines are explicitly recorded positive integer minor units plus currency and
reason; there is no automatic tariff, payment processing or accounting posting.

Validation includes two independent PHP workers on a shared SQLite database:
competing checkout and check-in retry versus ready expiry. SQLite can reject
an overlapping writer as busy; rejected transactions leave no partial allocation.
These tests establish persisted invariants on SQLite, not MySQL/PostgreSQL
row-lock behavior. Run the same scenarios on the production database before
deployment.
