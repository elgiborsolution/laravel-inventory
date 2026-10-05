# Laravel Inventory Project

`elgibor-solution/laravel-inventory-project` is an independent vertical that
depends only on Inventory Core and owns all `invp_*` tables.

Each ProjectAllocation stores a polymorphic project reference, Core Site and
warehouse organization references, one stock Item, and exactly one Core
Reservation. Site is an existing Core organization ancestor (or the warehouse
itself); this package does not introduce an organization level.

Replenishment creates a distinct allocation and Reservation. Reallocation is an
atomic release from the source Reservation plus a new destination allocation,
with the explicit pair stored in `invp_project_reallocations`. A failed
destination rolls the source release back.

Partial material draw posts an ordinary Core Goods Issue and links its exact
line quantity through `ReservationConsumptionData`. Reporting sums persisted
Reservation quantities and consumption links rather than inferring allocation
from unrelated Stock Ledger movements.

Project publishes no sector preset and registers no Document Type,
MovementPolicy, or CostingDriver. Accounting and approval remain governed by
Core; allocation itself works when both optional bridges are absent.
