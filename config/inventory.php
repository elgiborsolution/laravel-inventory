<?php

return [
    'organization' => ['levels' => ['holding' => false, 'company' => false, 'business_unit' => false, 'branch' => true, 'outlet' => false, 'department' => false, 'warehouse' => true]],
    'storage' => ['levels' => ['warehouse' => true, 'zone' => false, 'aisle' => false, 'rack' => true, 'shelf' => false, 'bin' => false, 'pallet' => false]],
    'costing' => ['default_method' => 'fifo', 'scope' => 'warehouse', 'negative_stock_cost' => 'last_known'],
    'inventory_model' => ['default' => 'standard'],
    'idempotency' => ['mode' => 'return_existing'],
    'policies' => [
        'posting' => ['enabled' => true],
        // Add "reservation" to applies_to when backorders must be rejected at reservation time.
        'negative_stock' => ['mode' => 'block', 'applies_to' => ['goods_issue']],
        'certificate' => ['enabled' => false, 'categories' => []],
        'reservation' => ['enabled' => true],
    ],
    'accounting' => [
        'enabled' => false,
        'connection' => null,
        'tenant_payload_key' => null,
        'service_code_map' => [
            'purchase_receipt' => 'PURCHASE_CREDIT',
            'goods_issue' => ['SALES_CASH', 'SALES_CASH_VAT', 'SALES_CREDIT', 'SALES_CREDIT_VAT'],
            'sales_delivery' => ['SALES_CASH', 'SALES_CASH_VAT', 'SALES_CREDIT', 'SALES_CREDIT_VAT'],
            'customer_return' => 'SALES_RETURN',
            'supplier_return' => 'PURCHASE_RETURN',
            'positive_adjustment' => 'STOCK_ADJUSTMENT_PLUS',
            'negative_adjustment' => 'STOCK_ADJUSTMENT_MINUS',
            'warehouse_transfer.intra_company' => null,
            'warehouse_transfer.cross_company' => 'STOCK_TRANSFER',
        ],
    ],
    'approval' => [
        'rejection_status_map' => [],
    ],
    'events' => ['after_commit' => true],
];
