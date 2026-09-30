<?php

return [
    'name' => 'VmsOpenOps',
    'version' => '1.0.0',
    'jumpseat' => [
        'enabled' => true,
        'cost_per_nm' => 250,
    ],
    'ferry' => [
        'enabled' => true,
        'cost_per_nm' => 500,
        'require_certification' => true,
    ],
    'require_reason' => true,
    'max_reason_length' => 500,
];