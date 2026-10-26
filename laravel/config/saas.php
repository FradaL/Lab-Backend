<?php

return [
    'demo_clinical_reason_id' => env('SAAS_DEMO_CLINICAL_REASON_ID'),
    'bootstrap' => [
        'enabled' => env('SAAS_BOOTSTRAP_ENABLED', false),
        'user_id' => env('SAAS_BOOTSTRAP_USER_ID'),
        'allow_production' => env('SAAS_BOOTSTRAP_ALLOW_PRODUCTION', false),
    ],
];
