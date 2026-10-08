# Approval Bridge

Inventory Core can pause a submitted document through the optional
`e-solution/laravel-approval-flow` package. Core owns `inv_documents.status`;
the external package owns `inv_documents.approval_status`. Core never polls the
external workflow and owns no approval tables.

## Activation and prerequisites

The real bridge is selected when
`ESolution\ApprovalFlow\Services\WorkflowEngine` is installed. Otherwise the
Null Bridge posts immediately without an external call.

The host project must configure the external package with:

- `approval-flow.default_status_field = approval_status`;
- a concrete `ESolution\ApprovalFlow\Contracts\IdentityResolver`;
- published/active Workflows and matching Rules for Inventory Document Types;
- an explicit `enforce_service_auth` decision for HTTP, queue, and console
  execution.

Validate these prerequisites during deployment:

```bash
php artisan inventory:approval:validate
```

## Submit and resume lifecycle

The bridge calls `checkApprovalRequired()` with module, action, header data,
line detail data, and tenant ID. No matching Rule means immediate posting. A
matching Rule is submitted once, after which Core moves `status` to
`waiting_approval` and creates no stock effect.

The external state driver writes one of `pending_approval`, `approved`,
`rejected`, or `cancelled` to `approval_status`. The Core observer reacts only
when that column changes:

- `approved`: transition Core to `approved`, then resume costing, ledger,
  accounting, Stock Card, and final posting exactly once;
- `rejected`: apply `inventory.approval.rejection_status_map`, defaulting to
  `draft`;
- `cancelled`: leave Core status unchanged.

The approval resume path locks the Document row and checks posting completion
markers. Duplicate callback delivery therefore becomes a safe no-op.

## Ownership and non-goals

Core does not implement approval Rules, delegation, SLA, instant approval,
approval audit chains, metrics, retention, or external authorization. Projects
use those capabilities directly from the external package.

Re-audit the adapter whenever the external package changes its major version,
especially the `checkApprovalRequired()` and `submit()` signatures, status
vocabulary, state-driver configuration, and workflow table fields.


## Contoh input dan hasil

Ini kontrak bridge internal, bukan endpoint HTTP yang tersedia otomatis. Aplikasi
memanggil `Inventory::post(DocumentData)` seperti [panduan Core](SALES_PURCHASING_INTEGRATION.md#panduan-input-dan-hasil-service).
Tambahkan approvalAction='create', approvalData (array tambahan), approvalMetadata
(array metadata submission), dan tenantIdentity bila diperlukan. PostingEngine
mengirim data dokumen dan detail lines ke bridge.

Contoh argumen `ApprovalWorkflowGateway::checkApprovalRequired()` yang diproyeksikan
sebagai JSON (data/detailData dapat memiliki field tambahan dari Core):

```json
{
  "module":"purchase_receipt","action":"create",
  "data":{"document_id":1,"document_type":"purchase_receipt","organization_id":1,"trx_date":"2026-10-08"},
  "detailData":[{"item_id":1,"warehouse_id":1,"qty":10,"unit_cost":40000}],
  "tenantId":"tenant-1"
}
```

Contoh hasil gateway:

```json
{"required":true,"workflow_id":10,"rule_id":20}
```

Jika approval tidak diperlukan, hasil dapat `{"required":false}`. Jika diperlukan,
workflow_id/rule_id wajib ada; bila tidak ada, ApprovalConfigurationException
menggagalkan posting. Bridge memanggil `submit()` dengan parameter berikut:

```json
{"module":"purchase_receipt","approvableType":"ESolution\\Inventory\\Models\\Document","approvableId":"1","workflowId":10,"ruleId":20,"metadata":{},"tenantId":"tenant-1"}
```

`approvableType` mengikuti morph map host jika dikonfigurasi. submit mengembalikan
void; `ExternalApprovalBridge::checkAndSubmitIfRequired()` mengembalikan true jika
posting harus menunggu, false jika dapat diteruskan. NullApprovalBridge selalu
false. Hasil `Inventory::post()` tetap Document, dengan proyeksi misalnya:

```json
{"id":1,"document_type":"purchase_receipt","status":"waiting_approval"}
```

Saat callback eksternal yang tervalidasi mengubah approval_status, observer Core
menangani transisi/resume sesuai aturan bridge. Untuk jalur resume eksplisit setelah
status Core sudah approved:

```php
$document = \ESolution\Inventory\Facades\Inventory::resumeApproved(1);
$result = ['id' => $document->id, 'status' => $document->status->value];
// Proyeksi: {"id":1,"status":"posted"}
```

`PostingEngine::resumeApproved`, `ResumeApprovedDocument::handle(1)`, dan
`__invoke(1)` memakai proses yang sama. Jika sudah posted, hasil lama dikembalikan;
jika belum approved, DomainException (`Only an approved document can resume posting.`).
Jangan memaksa status approved dari request pengguna untuk melewati approval.
Data stok/jurnal belum ditulis saat waiting; resume menggunakan payload tersimpan,
bukan harga/qty baru dari callback. Auth callback, identity resolver, rejection map,
dan workflow aktif harus dipenuhi seperti bagian prasyarat di atas.
