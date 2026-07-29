<?php

return [
    'tagline' => env('REPAIR_TAGLINE', 'Electronics Repair Service Management'),
    'admin_login_hint' => env('ADMIN_LOGIN_HINT', 'admin@example.com'),
    'job_order_prefix' => env('JOB_ORDER_PREFIX', 'RSMS'),
    'seed_demo_data' => env('REPAIR_SEED_DEMO_DATA', env('APP_ENV') === 'production' ? false : true),
    'sms_required' => env('REPAIR_SMS_REQUIRED', true),
    'platform_admin' => [
        'name' => env('PLATFORM_ADMIN_NAME', 'Platform Administrator'),
        'email' => env('PLATFORM_ADMIN_EMAIL', 'platform@example.com'),
        'password' => env('PLATFORM_ADMIN_PASSWORD', 'password'),
    ],
];
