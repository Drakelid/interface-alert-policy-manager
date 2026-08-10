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
        'rate_limit' => env('IAPM_INGEST_RATE', '20000,1'),
        'clock_skew_seconds' => 900,
        // Requests at or above this fault count are encrypted into the durable
        // ingestion inbox and acknowledged with 202 before asynchronous replay.
        'async_threshold' => (int) env('IAPM_INGEST_ASYNC_THRESHOLD', 1000),
        'async_recovery' => (bool) env('IAPM_INGEST_ASYNC_RECOVERY', true),
        'inbox_workers' => (int) env('IAPM_INGEST_INBOX_WORKERS', 2),
        'inbox_batch_per_worker' => (int) env('IAPM_INGEST_BATCH_PER_WORKER', 1),
        'inbox_claim_timeout_seconds' => (int) env('IAPM_INGEST_CLAIM_TIMEOUT', 900),
        'inbox_max_pending' => (int) env('IAPM_INGEST_MAX_PENDING', 10000),
        'success_log_sample_rate' => (float) env('IAPM_INGEST_LOG_SAMPLE_RATE', 0.01),
        'heartbeat_write_seconds' => (int) env('IAPM_HEARTBEAT_WRITE_SECONDS', 30),
    ],
    'processing' => [
        'batch_size' => 500,
        'reconciliation_interval' => 1,
        'action_interval' => 1,
        // Per-run wall-clock budget for iapm:process-actions. A large outage can
        // produce more discovery and outbox work than one scheduler minute can drain;
        // the command stops after this many seconds and the next scheduled run (the
        // overlap lock has cleared) continues the backlog. Keep it under 60.
        // For very high burst volume, move delivery onto a queue with workers.
        'max_seconds' => 50,
        'reconcile_max_seconds' => (int) env('IAPM_RECONCILE_MAX_SECONDS', 50),
        'digest_devices_per_run' => (int) env('IAPM_DIGEST_DEVICES_PER_RUN', 100),
        'cleanup_batch_size' => (int) env('IAPM_CLEANUP_BATCH_SIZE', 1000),
        'cleanup_max_seconds' => (int) env('IAPM_CLEANUP_MAX_SECONDS', 300),
        'cleanup_pause_ms' => (int) env('IAPM_CLEANUP_PAUSE_MS', 10),
    ],
    // Encrypted settings are read repeatedly in hot paths. Keep a short local
    // cache so long-lived workers still observe administrative changes promptly.
    'settings_cache_seconds' => (float) env('IAPM_SETTINGS_CACHE_SECONDS', 5),
    'resolver' => [
        // Regex assignments cannot be indexed by value; cap the two matcher lists
        // so an accidental import cannot turn every interface resolution into an
        // unbounded regex workload.
        'max_regex_assignments' => 5000,
        'regex_subject_bytes' => 2048,
        'regex_backtrack_limit' => 100000,
        'regex_depth_limit' => 1000,
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
        // Failed gateway calls are retried by the durable outbox, not by holding a
        // worker in a tight loop. Delay doubles per failed claim and includes jitter.
        'retry_base_seconds' => (int) env('IAPM_RETRY_BASE_SECONDS', 15),
        'retry_max_seconds' => (int) env('IAPM_RETRY_MAX_SECONDS', 3600),
        'drain_batch' => (int) env('IAPM_OUTBOX_DRAIN_BATCH', 1000),
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
