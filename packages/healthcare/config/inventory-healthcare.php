<?php

return [
    'preset' => [
        'costing_method' => 'fefo',
        'tracking' => [
            'batch_required_on_receipt' => true,
            'expiry_required_on_receipt' => true,
            'required_batch_certificates_on_issue' => ['coa'],
            'expired_receipt_dispositions' => ['quarantine', 'disposal'],
        ],
    ],
];
