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
