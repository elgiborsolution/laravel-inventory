<?php

return [
    'default_valuation' => 'fifo', // fifo|average

    'valuation_scopes' => [
        'per_branch'    => true,
        'per_warehouse' => true,
        'per_rack'      => false,
    ],

    'accounts' => [
        'inventory'            => '1100-INV',
        'cogs'                 => '5100-COGS',
        'ap'                   => '2100-AP',
        'ar'                   => '1101-AR',
        'purchase_return'      => '5201-PurchaseReturn',
        'sales_return'         => '4102-SalesReturn',
        'inventory_gain'       => '5202-InvGain',
        'inventory_loss'       => '5203-InvLoss',
        'inventory_interbranch'=> '1180-INV-INTRANSIT',
    ],

    'account_overrides' => [
        // 'BR-A' => ['inventory' => '11A0-INV-A', 'cogs' => '51A0-COGS-A'],
    ],

    'item_type_stages' => [
        'regular'   => ['pickup_admin_gudang','dibawa_salesman','diserahkan_toko'],
        'fast_move' => ['keluar_gudang','diterima_toko'],
    ],

    'stage_triggers' => [
        'recognize_cogs_on' => 'final', // final|custom
        'custom_stage'      => null,
    ],
];
