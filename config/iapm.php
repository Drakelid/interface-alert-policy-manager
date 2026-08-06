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
        // Per-run wall-clock budget for iapm:process-actions. A large outage can
        // produce more notifications than one minute of synchronous sends can drain;
        // the command stops after this many seconds and the next scheduled run (the
        // overlap lock has cleared) continues the backlog. Keep it under 60.
        // For very high burst volume, move delivery onto a queue with workers.
        'max_seconds' => 50,
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
