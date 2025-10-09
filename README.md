# esolution/inventory-ledger

Inventory & costing (FIFO / Moving Average) with journal posting for Laravel. Supports multi-branch, multi-warehouse, multi-rack, configurable movement stages per item type, and full document set: purchase, sale, purchase return, sales return, stock opname, consignment, and internal transfers (rack/warehouse/branch).

## Requirements
- PHP >= 8.1
- Laravel 9 / 10 / 11

## Installation
1. Add the package:
   ```bash
   composer require elgibor-solution/laravel-inventory:*
   ```
2. Publish config and migrate:
   ```bash
   php artisan vendor:publish --tag=inventory-config
   php artisan migrate
   ```

## Configuration
See `config/inventory.php`:
- `default_valuation`: `fifo|average`
- `valuation_scopes`: split cost layers per branch/warehouse/rack
- `accounts`: default accounts; override per branch via `account_overrides`

## Quick Start
```php
use ESolution\Inventory\Facades\Inventory;
use ESolution\Inventory\DTO\{DocumentData, LineData};
use ESolution\Inventory\Enums\DocumentType as DT;

// Purchase
$doc = DocumentData::make([
  'type' => DT::PURCHASE->value,
  'date' => now()->toDateString(),
  'ref'  => 'PO-001',
  'lines'=> [
    LineData::make(itemId: 1, branchId: 1, warehouseId: 1, rackId: null, qty: 10, unitCost: 10000),
    LineData::make(itemId: 1, branchId: 1, warehouseId: 1, rackId: null, qty: 20, unitCost: 11000),
  ],
]);
Inventory::post($doc);
```

## Documents
- `purchase`, `sale`, `purchase_return`, `sales_return`, `stock_opname`, `consignment`
- `transfer_rack`, `transfer_warehouse`, `transfer_branch`

## Stages
Define per item type in `config/inventory.php`. COGS recognized on the final stage by default.

## Reversal / Void
Use your own document management to create reversing documents (package is append-only).

## Notes
- Drivers use `lockForUpdate()` on cost layers to avoid races.
- Average driver computes current average on the fly from layers for simplicity.
- You can extend `JournalManager` to integrate with your GL system.

## License
Apache-2.0
