<?php

return [
    'allowed_item_types' => ['stock'],
    'preset' => [
        'tracking' => [
            'asset_checkout_enabled' => true,
            'serial_required_on_receipt' => true,
            'serial_required_on_issue' => true,
        ],
    ],
];
