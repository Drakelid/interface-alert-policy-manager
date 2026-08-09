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
 *   sudo -u librenms env RECOVERED=2000000 OPEN=5000 DEVICES=20000 BATCH=2000 \
 *     php artisan tinker --execute="require '/opt/iapm/interface-alert-policy-manager/tools/loadtest/seed.php';"
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;

$recoveredValue = getenv('RECOVERED');
$openValue = getenv('OPEN');
$recovered = max(0, $recoveredValue === false ? 2_000_000 : (int) $recoveredValue);
$open = max(0, $openValue === false ? 5_000 : (int) $openValue);
$batch = max(1, min(4_000, (int) (getenv('BATCH') ?: 2_000)));
$devices = max(1, (int) (getenv('DEVICES') ?: 20_000));
$policyCount = max(0, (int) (getenv('POLICIES') ?: 1_000));
$assignmentCount = max(0, (int) (getenv('ASSIGNMENTS') ?: 5_000));
$actionCount = max(0, (int) (getenv('ACTIONS') ?: 1_000));
$base = 900_000_000; // synthetic id space — will not collide with real devices/ports

DB::connection()->disableQueryLog();
echo "Seeding {$recovered} recovered + {$open} open incidents across ~{$devices} devices...\n";
echo "(synthetic id space >= {$base}; rows tagged incident_key=loadtest:*)\n";

$start = microtime(true);
$now = now();
$seq = max(0, (int) (getenv('SEQUENCE_START') ?: 0));
$flush = function (array &$rows): void {
    if ($rows) {
        DB::table('iapm_incidents')->insert($rows);
        $rows = [];
    }
};

echo "Seeding {$policyCount} policies, {$assignmentCount} indexed assignments and {$actionCount} actions...\n";
$policyRows = [];
for ($i = 1; $i <= $policyCount; $i++) {
    $policyRows[] = ['name' => 'loadtest:policy:'.$i, 'enabled' => true, 'priority' => $i % 100, 'created_at' => $now, 'updated_at' => $now];
}
foreach (array_chunk($policyRows, $batch) as $chunk) {
    DB::table('iapm_policies')->insert($chunk);
}
$policyIds = DB::table('iapm_policies')->where('name', 'like', 'loadtest:policy:%')->orderBy('id')->pluck('id')->all();
if ($policyIds !== []) {
    $destination = Destination::firstOrCreate(['name' => 'loadtest:destination'], [
        'type' => 'generic_webhook',
        'enabled' => false,
        'configuration_encrypted' => ['url' => 'https://example.com/loadtest', 'mode' => 'json'],
    ]);
    $types = ['port', 'device', 'location', 'interface_type', 'ifname_regex', 'default'];
    $assignmentRows = [];
    for ($i = 0; $i < $assignmentCount; $i++) {
        $type = $types[$i % count($types)];
        $assignmentRows[] = [
            'policy_id' => $policyIds[$i % count($policyIds)],
            'assignment_type' => $type,
            'assignment_reference' => match ($type) {
                'port', 'device', 'location' => (string) ($base + $i),
                'interface_type' => 'ethernetCsmacd',
                default => null,
            },
            'match_expression' => $type === 'ifname_regex' ? '/^xe-/' : null,
            'match_mode' => 'any',
            'priority' => $i % 100,
            'enabled' => true,
            'metadata_json' => json_encode(['receivers' => ['loadtest-'.$i]]),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    foreach (array_chunk($assignmentRows, $batch) as $chunk) {
        DB::table('iapm_assignments')->insert($chunk);
    }
    $actionRows = [];
    for ($i = 0; $i < $actionCount; $i++) {
        $actionRows[] = [
            'policy_id' => $policyIds[$i % count($policyIds)],
            'destination_id' => $destination->id,
            'phase' => 'trigger',
            'receivers_json' => json_encode(['loadtest-'.$i]),
            'enabled' => true,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    foreach (array_chunk($actionRows, $batch) as $chunk) {
        DB::table('iapm_policy_actions')->insert($chunk);
    }
}

// --- Recovered history, spread across the past year (the "noise" the hot paths must skip) ---
$rows = [];
$done = 0;
for ($i = 0; $i < $recovered; $i++) {
    $seq++;
    $dev = $base + ($seq % $devices);
    $rec = $now->copy()->subMinutes(random_int(60, 525_600)); // up to ~365 days ago
    $rows[] = [
        'incident_key' => 'loadtest:'.$seq,
        'episode_uuid' => (string) Str::uuid(),
        'device_id' => $dev, 'port_id' => $base + $seq, 'policy_id' => null,
        'state' => 'recovered', 'severity' => 'critical',
        'first_seen_at' => $rec->copy()->subMinutes(random_int(5, 240))->format('Y-m-d H:i:s'),
        'triggered_at' => $rec->copy()->subMinutes(random_int(1, 5))->format('Y-m-d H:i:s'),
        'last_seen_at' => $rec->format('Y-m-d H:i:s'),
        'recovered_at' => $rec->format('Y-m-d H:i:s'),
        'notification_count' => 1,
        'context_json' => json_encode(['hostname' => 'seed-'.$dev, 'ifName' => 'xe-0/0/'.($seq % 48)]),
        'created_at' => $rec->format('Y-m-d H:i:s'), 'updated_at' => $rec->format('Y-m-d H:i:s'),
    ];
    if (count($rows) >= $batch) {
        $flush($rows);
        $done += $batch;
        if ($done % 100_000 === 0) {
            echo '  '.number_format($done)." recovered...\n";
        }
    }
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
        'episode_uuid' => (string) Str::uuid(),
        'device_id' => $dev, 'port_id' => $base + $seq, 'policy_id' => null,
        'state' => $st, 'severity' => ($i % 4 === 0) ? 'warning' : 'critical',
        'first_seen_at' => $now->copy()->subMinutes(random_int(5, 600))->format('Y-m-d H:i:s'),
        'triggered_at' => $trig,
        'last_seen_at' => $now->format('Y-m-d H:i:s'),
        'recovered_at' => null,
        'suppression_reason' => $st === 'suppressed' ? 'no_policy' : null,
        'notification_count' => 0,
        'context_json' => json_encode(['hostname' => 'seed-'.$dev, 'ifName' => 'xe-0/0/'.($seq % 48)]),
        'created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s'),
    ];
    if (count($rows) >= $batch) {
        $flush($rows);
    }
}
$flush($rows);

$elapsed = round(microtime(true) - $start, 1);
$total = DB::table('iapm_incidents')->where('incident_key', 'like', 'loadtest:%')->count();
echo "Done in {$elapsed}s. Load-test incidents now in table: ".number_format($total)."\n";
echo "Next: run loadtest.php to time the hot-path queries; cleanup.php to remove them.\n";
