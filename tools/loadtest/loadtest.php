<?php

/**
 * IAPM load test — times the queries that must stay fast at fleet scale and shows
 * which index each one uses, so you can confirm the per-minute passes and the
 * Overview won't get sluggish against a large iapm_incidents table.
 *
 * Run AFTER seed.php:
 *   sudo -u librenms php artisan tinker --execute="require '/opt/iapm/interface-alert-policy-manager/tools/loadtest/loadtest.php';"
 *
 * Add RUN_COMMANDS=1 to also time the real iapm:reconcile / iapm:process-actions
 * commands end-to-end (note: those mutate — they will recover the synthetic open
 * incidents, so re-seed if you want to repeat).
 */

use App\Models\Port;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;

$queryCount = 0;
$failures = [];
$hotQueryLimitMs = (float) (getenv('HOT_QUERY_LIMIT_MS') ?: 50);
$resolverLimitMs = (float) (getenv('RESOLVER_LIMIT_MS') ?: 2000);
$resolverQueryLimit = (int) (getenv('RESOLVER_QUERY_LIMIT') ?: 25);
$peakMemoryLimitMiB = (float) (getenv('PEAK_MEMORY_LIMIT_MIB') ?: 256);
DB::listen(function () use (&$queryCount): void {
    $queryCount++;
});
$startedWith = memory_get_usage(true);

$timeq = function (string $label, string $sql, array $binds = [], int $runs = 3) use (&$failures, $hotQueryLimitMs): void {
    try {
        $ex = DB::select('EXPLAIN '.$sql, $binds);
        $plan = implode(' | ', array_map(fn ($r) => ($r->key ?? 'NO-INDEX').' (~'.number_format((int) ($r->rows ?? 0)).' rows)', $ex));
    } catch (Throwable $e) {
        $plan = 'EXPLAIN failed: '.$e->getMessage();
    }
    $best = PHP_FLOAT_MAX;
    for ($i = 0; $i < $runs; $i++) {
        $t = microtime(true);
        DB::select($sql, $binds);
        $best = min($best, (microtime(true) - $t) * 1000);
    }
    printf("  %-26s %9.1f ms   %s\n", $label, $best, $plan);
    if ($best > $hotQueryLimitMs || str_contains($plan, 'NO-INDEX')) {
        $failures[] = "{$label}: {$best}ms, {$plan}";
    }
};

$open = "('pending','active','acknowledged','suppressed')";
$cut48 = now()->subHours(48)->format('Y-m-d H:i:s');
$cut24 = now()->subDay()->format('Y-m-d H:i:s');

echo "Row counts by state:\n";
foreach (DB::select('SELECT state, count(*) c FROM iapm_incidents GROUP BY state') as $r) {
    echo '  '.str_pad($r->state, 14).number_format($r->c)."\n";
}
printf("\nSeeded scale: %s incidents, %s policies, %s assignments, %s actions\n",
    number_format(DB::table('iapm_incidents')->where('incident_key', 'like', 'loadtest:%')->count()),
    number_format(DB::table('iapm_policies')->count()),
    number_format(DB::table('iapm_assignments')->count()),
    number_format(DB::table('iapm_policy_actions')->count()));

echo "\nHot-path query timings (best of 3, with chosen index):\n";
$timeq('Overview open counts', "SELECT state, count(*) c FROM iapm_incidents WHERE state IN $open GROUP BY state");
$timeq('Overview recent 25', 'SELECT * FROM iapm_incidents ORDER BY last_seen_at DESC LIMIT 25');
$timeq('missing_policies tile', "SELECT count(*) c FROM iapm_incidents WHERE state='suppressed' AND suppression_reason='no_policy'");
$timeq('recovered_24h tile', "SELECT count(*) c FROM iapm_incidents WHERE state='recovered' AND recovered_at >= ?", [$cut24]);
$timeq('reconcile 1st chunk', "SELECT * FROM iapm_incidents WHERE state IN $open AND id > 0 ORDER BY id LIMIT 500");
$timeq('process-actions chunk', "SELECT * FROM iapm_incidents WHERE (state IN ('pending','active','acknowledged') OR (state='recovered' AND recovered_at >= ?)) AND id > 0 ORDER BY id LIMIT 500", [$cut48]);

$ports = Port::query()->with(['device.location', 'device.groups', 'groups'])->limit((int) (getenv('RESOLVER_PORTS') ?: 500))->get();
if ($ports->isNotEmpty()) {
    $contexts = app(InterfaceContextService::class);
    $resolver = app(PolicyResolver::class);
    $beforeQueries = $queryCount;
    $t = microtime(true);
    foreach ($ports as $port) {
        $resolver->resolve($contexts->forPort($port), writeCache: false);
    }
    $resolverMs = (microtime(true) - $t) * 1000;
    $resolverQueries = $queryCount - $beforeQueries;
    printf("  %-26s %9.1f ms   %s ports / %s queries\n", 'resolver + matrix batch', $resolverMs, number_format($ports->count()), number_format($resolverQueries));
    if ($resolverMs > $resolverLimitMs || $resolverQueries > $resolverQueryLimit) {
        $failures[] = "resolver: {$resolverMs}ms / {$resolverQueries} queries";
    }
} else {
    echo "  resolver + matrix batch    SKIPPED (test DB has no LibreNMS ports)\n";
}

echo "\nWhat to look for: every timing should be low-single-digit to low-double-digit ms,\n";
echo "and NONE of the hot-path scans should say NO-INDEX. 'Overview recent 25' should use\n";
echo "iapm_incident_last_seen_idx; the chunk scans should use a state index, not the PK.\n";

if (getenv('RUN_COMMANDS')) {
    echo "\nEnd-to-end command timings (these mutate the open synthetic incidents):\n";
    // Process actions first so the timing includes the seeded open working set;
    // reconciliation then mutates those synthetic incidents to recovered.
    foreach (['iapm:process-actions', 'iapm:reconcile'] as $cmd) {
        $t = microtime(true);
        Artisan::call($cmd);
        printf("  %-22s %6.2f s   %s\n", $cmd, microtime(true) - $t, trim(Artisan::output()));
    }
}

$peakMiB = memory_get_peak_usage(true) / 1048576;
printf("\nInstrumentation: %s SQL queries observed; %.1f MiB peak memory (%.1f MiB growth).\n",
    number_format($queryCount), $peakMiB, (memory_get_peak_usage(true) - $startedWith) / 1048576);
if ($peakMiB > $peakMemoryLimitMiB) {
    $failures[] = "peak memory: {$peakMiB} MiB";
}
echo "Ingestion throughput is measured by the integration suite's authenticated multi-fault fixtures;\n";
echo "use RUN_COMMANDS=1 for the mutating action/reconcile timings on this seeded scale.\n";
if ($failures !== []) {
    throw new RuntimeException("Load-test thresholds failed:\n - ".implode("\n - ", $failures));
}
