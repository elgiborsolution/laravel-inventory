<?php

return [
    'preset' => ['tracking' => [
        'serial_required_on_receipt' => true,
        'serial_required_on_issue' => true,
        'required_serial_certificates_on_issue' => ['compliance'],
    ]],
    'accounting' => ['enabled' => false],
];
