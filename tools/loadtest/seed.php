<?php

/**
 * IAPM load-test seeder — bulk-inserts synthetic incidents to simulate a large fleet
 * with a year of history, so you can measure whether the per-minute passes and the
 * Overview stay fast at 500k+ interface scale.
 *
 * Every row is tagged with an `incident_key` of `loadtest:<n>` and uses a synthetic
 * device/port id space (>= 900,000,000) that cannot collide with real LibreNMS rows,
 * so cleanup.php can remove exactly what this created.
 *
 * RUN ON A TEST DATABASE ONLY.
 *
 * Usage (tune via env):
 *   sudo -u librenms env RECOVERED=2000000 OPEN=5000 DEVICES=20000 BATCH=5000 \
 *     php artisan tinker --execute="require '/opt/iapm/interface-alert-policy-manager/tools/loadtest/seed.php';"
 */

use Illuminate\Support\Facades\DB;

$recovered = (int) (getenv('RECOVERED') ?: 2_000_000);
$open      = (int) (getenv('OPEN') ?: 5_000);
$batch     = (int) (getenv('BATCH') ?: 5_000);
$devices   = max(1, (int) (getenv('DEVICES') ?: 20_000));
$base      = 900_000_000; // synthetic id space — will not collide with real devices/ports

DB::connection()->disableQueryLog();
echo "Seeding {$recovered} recovered + {$open} open incidents across ~{$devices} devices...\n";
echo "(synthetic id space >= {$base}; rows tagged incident_key=loadtest:*)\n";

$start = microtime(true);
$now = now();
$seq = 0;
$flush = function (array &$rows): void { if ($rows) { DB::table('iapm_incidents')->insert($rows); $rows = []; } };

// --- Recovered history, spread across the past year (the "noise" the hot paths must skip) ---
$rows = []; $done = 0;
for ($i = 0; $i < $recovered; $i++) {
    $seq++;
    $dev = $base + ($seq % $devices);
    $rec = $now->copy()->subMinutes(random_int(60, 525_600)); // up to ~365 days ago
    $rows[] = [
        'incident_key' => 'loadtest:'.$seq,
        'device_id' => $dev, 'port_id' => $base + $seq, 'policy_id' => null,
        'state' => 'recovered', 'severity' => 'critical',
        'first_seen_at' => $rec->copy()->subMinutes(random_int(5, 240))->format('Y-m-d H:i:s'),
        'triggered_at'  => $rec->copy()->subMinutes(random_int(1, 5))->format('Y-m-d H:i:s'),
        'last_seen_at'  => $rec->format('Y-m-d H:i:s'),
        'recovered_at'  => $rec->format('Y-m-d H:i:s'),
        'notification_count' => 1,
        'context_json' => json_encode(['hostname' => 'seed-'.$dev, 'ifName' => 'xe-0/0/'.($seq % 48)]),
        'created_at' => $rec->format('Y-m-d H:i:s'), 'updated_at' => $rec->format('Y-m-d H:i:s'),
    ];
    if (count($rows) >= $batch) { $flush($rows); $done += $batch; if ($done % 100_000 === 0) echo '  '.number_format($done)." recovered...\n"; }
}
$flush($rows);

// --- Open working set: weighted mix of active / pending / suppressed(no_policy) ---
$weighted = ['active', 'active', 'active', 'pending', 'suppressed'];
$rows = [];
for ($i = 0; $i < $open; $i++) {
    $seq++;
    $dev = $base + ($seq % $devices);
    $st = $weighted[$i % count($weighted)];
    $trig = $st === 'active' ? $now->copy()->subMinutes(random_int(1, 120))->format('Y-m-d H:i:s') : null;
    $rows[] = [
        'incident_key' => 'loadtest:'.$seq,
        'device_id' => $dev, 'port_id' => $base + $seq, 'policy_id' => null,
        'state' => $st, 'severity' => ($i % 4 === 0) ? 'warning' : 'critical',
        'first_seen_at' => $now->copy()->subMinutes(random_int(5, 600))->format('Y-m-d H:i:s'),
        'triggered_at'  => $trig,
        'last_seen_at'  => $now->format('Y-m-d H:i:s'),
        'recovered_at'  => null,
        'suppression_reason' => $st === 'suppressed' ? 'no_policy' : null,
        'notification_count' => 0,
        'context_json' => json_encode(['hostname' => 'seed-'.$dev, 'ifName' => 'xe-0/0/'.($seq % 48)]),
        'created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s'),
    ];
    if (count($rows) >= $batch) { $flush($rows); }
}
$flush($rows);

$elapsed = round(microtime(true) - $start, 1);
$total = DB::table('iapm_incidents')->where('incident_key', 'like', 'loadtest:%')->count();
echo "Done in {$elapsed}s. Load-test incidents now in table: ".number_format($total)."\n";
echo "Next: run loadtest.php to time the hot-path queries; cleanup.php to remove them.\n";
