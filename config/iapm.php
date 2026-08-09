<?php

return [
    'route_prefix' => 'plugin/interface-alert-policy-manager',
    'ingestion' => [
        'max_bytes' => 1048576,
        // requests,minutes ceiling on the ingestion endpoint. This is a DoS guard on
        // the pre-auth path, keyed by source IP — so it caps ALL of LibreNMS's alert
        // POSTs together. A fleet-wide event posts one webhook per alerting device;
        // on a large network (thousands of devices) 120/min would 429 — and dropped
        // alerts are lost. Default is generous; raise it further for very large fleets
        // (IAPM_INGEST_RATE) and firewall the endpoint to the LibreNMS host.
        'rate_limit' => env('IAPM_INGEST_RATE', '2000,1'),
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
    'resolver' => [
        // Regex assignments cannot be indexed by value; cap the two matcher lists
        // so an accidental import cannot turn every interface resolution into an
        // unbounded regex workload.
        'max_regex_assignments' => 5000,
    ],
    'http' => [
        'connect_timeout' => 5,
        'timeout' => 15,
        'retries' => 2,
        'retry_delay_ms' => 500,
        'verify_tls' => true,
        'allow_private_networks' => false,
    ],
    // Optional queued delivery (dispatch_mode setting = "queue"). Leave connection
    // null to use LibreNMS's default queue connection; set IAPM_QUEUE_CONNECTION to a
    // real async driver (redis/database) and run `php artisan queue:work` for true
    // concurrency during large storms. With a "sync" connection jobs run inline
    // (still works, no worker needed, but no concurrency gain).
    'queue' => [
        'connection' => env('IAPM_QUEUE_CONNECTION'),
        'name' => env('IAPM_QUEUE_NAME', 'iapm'),
        'tries' => 3,
        'timeout' => 60,
        // How many queue workers the scheduler keeps running when dispatch_mode=queue.
        // Each is one concurrent SMS in flight — raise for wider parallel delivery,
        // but not beyond what your SMS gateway accepts concurrently. Set 0 to let the
        // scheduler manage none (e.g. when you run dedicated systemd workers instead).
        'workers' => (int) env('IAPM_QUEUE_WORKERS', 3),
    ],
    'sms' => [
        'gateway_url' => env('IAPM_SMS_GATEWAY_URL'),
        'username' => env('IAPM_SMS_GATEWAY_USERNAME'),
        'password' => env('IAPM_SMS_GATEWAY_PASSWORD'),
        'default_receiver' => env('IAPM_SMS_DEFAULT_RECEIVER'),
        'message_length' => 480,
    ],
];
