<?php

return [
    'route_prefix' => 'plugin/interface-alert-policy-manager',
    'ingestion' => [
        'max_bytes' => 1048576,
        'rate_limit' => '120,1',
        'clock_skew_seconds' => 900,
    ],
    'processing' => [
        'batch_size' => 500,
        'reconciliation_interval' => 1,
        'action_interval' => 1,
    ],
    'http' => [
        'connect_timeout' => 5,
        'timeout' => 15,
        'retries' => 2,
        'retry_delay_ms' => 500,
        'verify_tls' => true,
        'allow_private_networks' => false,
    ],
    'sms' => [
        'gateway_url' => env('IAPM_SMS_GATEWAY_URL'),
        'username' => env('IAPM_SMS_GATEWAY_USERNAME'),
        'password' => env('IAPM_SMS_GATEWAY_PASSWORD'),
        'default_receiver' => env('IAPM_SMS_DEFAULT_RECEIVER'),
        'message_length' => 480,
    ],
];
