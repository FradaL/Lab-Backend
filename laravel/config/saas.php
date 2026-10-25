<?php

return [
    'bootstrap' => [
        'enabled' => env('SAAS_BOOTSTRAP_ENABLED', false),
        'user_id' => env('SAAS_BOOTSTRAP_USER_ID'),
        'allow_production' => env('SAAS_BOOTSTRAP_ALLOW_PRODUCTION', false),
    ],
];
