<?php

/**
 * Removes everything seed.php created (incident_key = loadtest:*, synthetic id space
 * >= 900,000,000). Incident events and delivery logs cascade with the incident; the
 * outages table is cleaned by the synthetic id range.
 *
 *   sudo -u librenms php artisan tinker --execute="require '/opt/iapm/interface-alert-policy-manager/tools/loadtest/cleanup.php';"
 */

use Illuminate\Support\Facades\DB;

DB::connection()->disableQueryLog();

echo "Deleting load-test incidents (cascades events + delivery logs)...\n";
$total = 0;
do {
    $deleted = DB::table('iapm_incidents')->where('incident_key', 'like', 'loadtest:%')->limit(5000)->delete();
    $total += $deleted;
    if ($deleted) {
        echo '  deleted '.number_format($total)."...\n";
    }
} while ($deleted >= 5000);

$outages = 0;
do {
    $deleted = DB::table('iapm_outages')->where('port_id', '>=', 900_000_000)->limit(5000)->delete();
    $outages += $deleted;
} while ($deleted >= 5000);

echo 'Removed '.number_format($total).' incidents and '.number_format($outages)." outage records.\n";

$policies = DB::table('iapm_policies')->where('name', 'like', 'loadtest:policy:%')->delete();
$destinations = DB::table('iapm_destinations')->where('name', 'loadtest:destination')->delete();
echo 'Removed '.number_format($policies).' load-test policies and '.number_format($destinations)." destination records.\n";
