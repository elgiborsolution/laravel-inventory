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


## Adjustment, count, and supplier return mappings

The aliases `purchase`, `sale`, `purchase_return`, and `sales_return` fall back to
Core's corresponding receipt/delivery/return service-code mappings when the alias
has no explicit mapping. Host-provided overrides still take precedence.

Adjustments/counts and supplier returns no longer use the generic outgoing COGS
pair. Configure **verified external mapping keys** under
`inventory.accounting.document_mapping_keys.<document_type>`. No external keys or
stock-count service codes are guessed by Core. For `stock_count`/`stock_opname`,
also configure `service_code_map` explicitly when accounting is enabled.

| Operation | Required roles when amount is positive |
|---|---|
| Positive adjustment / count gain | `inventory_debit`, `gain_credit` |
| Negative adjustment / count loss | `loss_debit`, `inventory_credit` |
| Supplier return | `payable_debit`, `inventory_credit`; `gain_credit` if refund exceeds book cost, otherwise `loss_debit` for the shortfall |

Each role maps to a complete external `mapping_key` string, prefixed by the
selected service code in lowercase. Configure the exact document type used,
including aliases. For example, for a host-verified `PURCHASE_RETURN` service:

```php
'document_mapping_keys' => [
    'supplier_return' => [
        'payable_debit' => 'purchase_return_ap_d',
        'inventory_credit' => 'purchase_return_inventory_k',
        'gain_credit' => 'purchase_return_gain_k',
        'loss_debit' => 'purchase_return_loss_d',
    ],
],
```

These example keys must exist in the host accounting catalog before use. Core
calculates the role amounts but does not create mappings/accounts. Supplier return
requires `transactionPrice` on every line; refund is quantity times net invoice
price. For 5 units with book cost 45000 and invoice price 50000, the payload is
AP debit 250000, inventory credit 225000, gain credit 25000. Tax reversal remains a
caller-owned additional line with any related payable adjustment. Do not send the
same Core-owned AP/inventory/gain/loss amounts again in `additionalJournalLines`.

A count can contain gain and loss lines; both pairs go into one journal payload.
A zero-variance count skips accounting. Missing roles or invalid keys fail posting
and roll back inventory. During upgrade, add these mappings before enabling the
affected operations; existing generic COGS mappings alone are insufficient.
Integration tests use the fake gateway; real accounting catalog compatibility and
balanced tax-inclusive journals must still be verified in the host environment.


## Contoh payload dan hasil gateway

Bridge dipanggil PostingEngine dalam transaksi; controller memanggil
`Inventory::post()` dan tidak perlu membuat jurnal kedua. AccountingPostingData
membawa totalCost:float, direction:string, additionalJournalLines:array=[],
serviceCode:?string=null, tenantIdentity:mixed=null. Nilai biaya dihitung Core,
bukan dipercaya dari request pengguna.

Contoh input host untuk pembelian 10 @40000 tanpa pajak:

```php
use ESolution\Inventory\DTO\DocumentData;
use ESolution\Inventory\DTO\LineData;
use ESolution\Inventory\Facades\Inventory;

$document = Inventory::post(new DocumentData(
    type: 'purchase_receipt', organizationId: 1, trxDate: '2026-10-08',
    externalId: 'PI-001', lines: [new LineData(1, 1, 1, 10, unitCost: 40000)],
    additionalJournalLines: [['mapping_key' => 'purchase_credit_ap_k', 'amount' => 400000]],
    tenantIdentity: 'tenant-1',
));
```

Dengan mapping PURCHASE_CREDIT, payload yang masuk ke `AccountingJournalGateway::post`
(kode mapping harus benar-benar tersedia pada sistem accounting host):

```json
{
  "service_code":"PURCHASE_CREDIT","trx_date":"2026-10-08",
  "source_type":"ESolution\\Inventory\\Models\\Document","source_id":1,
  "items":[
    {"mapping_key":"purchase_credit_ap_k","amount":400000},
    {"mapping_key":"purchase_credit_inventory_d","amount":400000}
  ]
}
```

Tenant dikirim sebagai argumen kedua; LaravelAccountingJournalGateway dapat
menambahkannya ke payload pada tenant_payload_key yang dikonfigurasi. Morph map
host dapat mengubah source_type. Gateway memanggil JournalService eksternal dan
mengembalikan ID jurnal berupa **string**, misalnya `"123"`, bukan model Document.
`Inventory::post()` tetap mengembalikan Document Core; ID jurnal tidak otomatis
menjadi atribut response Document.

| Kontrak / method | Input contoh | Hasil / efek |
|---|---|---|
| `AccountingBridge::post` | Document + AccountingPostingData | ?string ID jurnal; null pada Null bridge, mapping explicit null, atau count tanpa mutasi. |
| `AccountingBridge::reverse` | Document asal + reason:string | void; mencari jurnal asli lalu memanggil gateway reverse, tanpa efek jika tidak ditemukan. Bukan API reversal stok dengan bridge aktif. |
| `AccountingJournalGateway::post` | array payload di atas, tenantIdentity=null | string ID, misalnya "123"; adaptor default mengharapkan journalByMapping mengembalikan objek ber-ID. |
| `findOriginalJournalId` | sourceType:string, sourceId:int/string | ?string, null jika belum ada jurnal non-reversal. |
| `reverse` | journalId:string, reason:string, tenantIdentity=null | void; hasil/retry ditentukan sistem accounting eksternal. |

Support service internal: `ServiceCodeResolver::resolve('purchase_receipt')`
menghasilkan `PURCHASE_CREDIT` pada config default; callerSelection opsional harus
diizinkan. `MappingKeyGuard::assertSafe($lines, 'PURCHASE_CREDIT')` -> void atau
AccountingMappingIncompleteException bila prefix/nilai tidak valid. Jangan memakai
mapping contoh sebagai jaminan keberadaan akun eksternal.

Contoh kegagalan: kode service hilang, mapping role return/gain/loss hilang, atau
`Accounting JournalService did not return a journal id.` Exception dikembalikan ke
posting dan transaksi lokal dibatalkan. Efek di sistem eksternal tetap memerlukan
jaminan transaksi/idempotency adaptor; retry aplikasi memakai externalId Core yang
sama. Paket ini tidak menjanjikan distributed rollback untuk layanan remote.
