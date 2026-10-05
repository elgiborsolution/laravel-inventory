<?php

return [
    'put_away' => [
        'default_strategy' => 'dynamic',
    ],
    'picking' => [
        'default_strategy' => 'fifo',
    ],
    'task_document_types' => [
        'purchase_receipt' => 'put_away',
        'customer_return' => 'put_away',
        'sales_delivery' => 'pick',
        'goods_issue' => 'pick',
    ],
];
