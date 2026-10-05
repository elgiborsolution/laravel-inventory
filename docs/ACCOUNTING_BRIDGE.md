# Accounting Bridge

Inventory Core does not own journals, accounts, fiscal periods, or any `acc_*`
table. Accounting is an optional synchronous bridge to
`elgibor-solution/laravel-accounting` and is disabled by default.

## Activation

Install the external package in the host application, publish the latest
Inventory configuration, set `inventory.accounting.enabled` to `true`, and run:

```bash
php artisan inventory:accounting:validate
```

When accounting is disabled, or the external package is absent, Core binds
`NullAccountingBridge`. Enabling accounting without installing its dependency
is reported as a deployment error by the validation command.

## Mapping rules

`inventory.accounting.service_code_map` is project-owned. A missing key fails
closed. An explicit `null` skips accounting for that Document Type. An array of
codes requires the caller to select one allowed code through
`DocumentData::$accountingServiceCode`.

Caller-owned revenue, VAT, cash, AR, and AP lines are supplied through
`DocumentData::$additionalJournalLines`. Core forwards them unchanged and only
adds inventory-derived cost lines. Every caller mapping key must begin with the
lowercase service-code prefix followed by `_`; Core never calculates tax.

## Transaction and reversal

The external call runs after inventory ledger costing and before Stock Card and
commit. Any external exception propagates and rolls back the complete posting.
Reversal delegates to the external `JournalService::reverse()` after a read-only
lookup by the original Inventory Document's morph type and ID. Core stores no
external journal foreign key and owns no external schema.

## Tenant context

Ambient tenancy remains owned by the host/external package. For integrations
that require an explicit payload field, configure
`inventory.accounting.tenant_payload_key` and pass the value through
`DocumentData::$tenantIdentity`.

## Compatibility audit

Re-audit the adapter whenever `elgibor-solution/laravel-accounting` changes its
major version. Verify `JournalService::journalByMapping()`, `reverse()`, returned
journal IDs, service catalog columns, and the read-only
`acc_journal_entries.source_type/source_id/is_reversal` lookup.
