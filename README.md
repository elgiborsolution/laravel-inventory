# elgibor-solution/laravel-inventory
**Comprehensive Laravel Inventory, Costing, and Journal Package**  
Multi-branch, multi-warehouse, multi-rack, configurable movement stages, FIFO & Average costing, with automatic journal posting.

---

## 🚀 Overview

`elgibor-solution/laravel-inventory` is a full-featured Laravel package for managing **inventory, accounting journal, and cost valuation (FIFO and Moving Average)**.  
It is designed for ERP, POS, and SaaS platforms that require accurate, auditable, and multi-location stock control — from branches and warehouses down to racks and stages.

### ✅ Key Features
- **Costing Methods:** FIFO and Moving Average
- **Multi-Location:** Multi-branch, multi-warehouse, multi-rack
- **Configurable Stages:** Customizable per item type
- **Full Document Set:** Purchase, Sales, Returns, Stock Opname, Consignment, Transfers
- **Automatic Journal Entries:** Predefined account mapping for Dr/Cr
- **Audit Trail:** Immutable stock ledger
- **Idempotent Posting:** via `external_id`
- **Modular Design:** Easily extendable or integrated into existing GL/ERP

---

## 📦 Installation


1. Install the package:
   ```bash
   composer require elgibor-solution/laravel-inventory:*
   ```

2. Publish configuration & migrate tables:
   ```bash
   php artisan vendor:publish --tag=inventory-config
   php artisan migrate
   ```

---

## ⚙️ Configuration (`config/inventory.php`)

```php
return [
    'default_valuation' => 'fifo', // 'fifo' | 'average'

    'valuation_scopes' => [
        'per_branch'    => true,
        'per_warehouse' => true,
        'per_rack'      => false,
    ],

    'accounts' => [
        'inventory'             => '1100-INV',
        'cogs'                  => '5100-COGS',
        'ap'                    => '2100-AP',
        'ar'                    => '1101-AR',
        'purchase_return'       => '5201-PurchaseReturn',
        'sales_return'          => '4102-SalesReturn',
        'inventory_gain'        => '5202-InvGain',
        'inventory_loss'        => '5203-InvLoss',
        'inventory_interbranch' => '1180-INV-INTRANSIT',
    ],

    'item_type_stages' => [
        'regular'   => ['pickup_admin_gudang', 'carried_by_salesman', 'delivered_to_store'],
        'fast_move' => ['warehouse_exit', 'received_by_store'],
    ],

    'stage_triggers' => [
        'recognize_cogs_on' => 'final',
    ],
];
```

---

## 🧠 Core Concepts

### 1. **Cost Valuation**
- **FIFO:** First In First Out — oldest stock layers consumed first.
- **Average:** Weighted moving average recalculated on every receipt.

### 2. **Location Hierarchy**
```
Branch → Warehouse → Rack → Stage
```
Every stock movement references branch, warehouse, and optionally rack.

### 3. **Stages**
Each item type can have its own movement stages.  
Example:

| Item Type | Stages |
|------------|---------------------------------------------|
| Regular    | pickup_admin_gudang → carried_by_salesman → delivered_to_store |
| Fast Move  | warehouse_exit → received_by_store |

---

## 📄 Supported Documents

| Document | Description | Stock Effect | Accounting Effect |
|-----------|--------------|---------------|--------------------|
| **Purchase** | Goods received from supplier | IN | Dr Inventory / Cr AP |
| **Sale** | Goods delivered to customer | OUT | Dr COGS / Cr Inventory |
| **Purchase Return** | Goods returned to supplier | OUT | Dr AP / Cr Inventory |
| **Sales Return** | Goods returned from customer | IN | Dr Inventory / Cr COGS |
| **Stock Opname** | Physical stock adjustment | IN/OUT | Dr/C Inventory Gain/Loss |
| **Consignment** | Goods sent to consignment | OUT | Optional (On/Off Balance) |
| **Transfer Rack** | Move between racks | IN/OUT | No journal |
| **Transfer Warehouse** | Move between warehouses | IN/OUT | No journal |
| **Transfer Branch** | Move between branches | IN/OUT | Dr Inventory (Dest) / Cr Inventory (Src) |

---

## 🧾 Usage Examples

(Examples section omitted here for brevity — same as full README above)

---

## 📊 Suggested SQL View

```sql
CREATE VIEW inv_item_balances AS
SELECT
  item_id, branch_id, warehouse_id, rack_id,
  SUM(CASE WHEN direction='in' THEN qty ELSE -qty END) AS qty_on_hand,
  SUM(CASE WHEN direction='in' THEN amount ELSE -amount END) AS amount_on_hand
FROM inv_stock_ledgers
GROUP BY item_id, branch_id, warehouse_id, rack_id;
```

---

## 🛡️ Best Practices
- Always post documents within a **DB transaction**.
- Never modify `inv_stock_ledgers` or `inv_cost_layers` manually.
- Use `external_id` to ensure idempotent synchronization.
- Add indexes `(item_id, branch_id, warehouse_id, rack_id, created_at)` for performance.
- `lockForUpdate()` is used to ensure cost consistency under concurrency.

---

## 🧰 Recommended Extensions
- Add `DocumentPosted` events for webhook integration.
- Extend `JournalManager` to push entries to your accounting module.
- Create `InventoryPolicy` to control stage posting permissions by user role.

---

## 📜 License
**Apache 2.0 License**  
© 2025 PT Elgibor Solusi Digital