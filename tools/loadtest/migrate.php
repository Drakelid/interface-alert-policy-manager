<?php

// The scale database is a schema clone whose core LibreNMS migration ledger is
// intentionally not authoritative. Apply only the pending audit migrations;
// normal installations continue to use `php artisan migrate --force`.
$root = dirname(__DIR__, 2).'/database/migrations/';
foreach ([
    '2026_08_08_235959_prepare_episode_identity_backfill.php',
    '2026_08_10_000001_add_storm_path_indexes.php',
    '2026_08_10_000002_add_durable_ingestion_inbox.php',
] as $file) {
    echo "Applying {$file}...\n";
    (require $root.$file)->up();
}
