<?php

return [
    // Core currently stock-tracks only the "stock" Item Type. WIP is represented
    // by a stock Item linked between parent/child Production Orders.
    'allowed_component_item_types' => ['stock'],
    'allowed_output_item_types' => ['stock'],
    'source_modes' => ['mts', 'mto', 'bto', 'ato'],
    'accounting' => [
        'enabled' => false,
        'blocked_reason' => 'Verified Manufacturing accounting service codes are not available.',
    ],
];
