# Laravel Inventory Healthcare

`elgibor-solution/laravel-inventory-healthcare` is an independent vertical that
depends only on Inventory Core and owns all `invh_*` tables.

`HealthcarePreset` merges mandatory receipt batch/expiry tracking, Core FEFO,
valid COA-on-issue, and controlled expired-receipt dispositions into an Item
without removing existing tracking settings.

Deterministic FEFO is Core-owned and does not require WMS. It excludes expired,
recalled, blocked, and certificate-ineligible batches, then orders eligible
layers by non-null expiry, `expires_at`, `received_at`, and Cost Layer ID.

Expired receipts are accepted only when their line metadata declares an allowed
controlled disposition (`quarantine` or `disposal`). They remain traceable but
cannot be issued. Recall records change only their batch's availability;
`RecallService::forwardTrace()` resolves outbound Core documents through the
immutable Cost Layer and Stock Ledger links.
