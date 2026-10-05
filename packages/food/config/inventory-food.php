<?php

return [
    'allowed_component_item_types' => ['stock'],
    'allowed_output_item_types' => ['stock'],
    'preset' => [
        'tracking' => [
            'batch_required_on_receipt' => true,
            'required_batch_certificates_on_issue' => ['halal'],
        ],
    ],
    'mto' => [
        'document_type' => 'food_order',
        'trigger_status' => 'posted',
    ],
    'accounting' => [
        'enabled' => false,
        'blocked_reason' => 'Verified Food accounting service codes are not available.',
    ],
];
