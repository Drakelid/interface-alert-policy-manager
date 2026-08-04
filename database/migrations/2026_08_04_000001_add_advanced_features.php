<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iapm_policies', function (Blueprint $t): void {
            if (! Schema::hasColumn('iapm_policies', 'suppress_uplink_down')) {
                $t->boolean('suppress_uplink_down')->default(false)->after('suppress_parent_down');
            }
            if (! Schema::hasColumn('iapm_policies', 'flap_threshold')) {
                $t->unsignedInteger('flap_threshold')->nullable()->after('suppress_uplink_down');
            }
            if (! Schema::hasColumn('iapm_policies', 'flap_window_seconds')) {
                $t->unsignedInteger('flap_window_seconds')->nullable()->after('flap_threshold');
            }
            if (! Schema::hasColumn('iapm_policies', 'flap_settle_seconds')) {
                $t->unsignedInteger('flap_settle_seconds')->nullable()->after('flap_window_seconds');
            }
        });

        // Append-only per-outage record for SLA/MTTR reporting. The incident row
        // is reused across outages (one per device+port), so historical outages
        // need their own immutable rows.
        if (! Schema::hasTable('iapm_outages')) {
            Schema::create('iapm_outages', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('incident_id')->nullable();
                $t->unsignedBigInteger('device_id');
                $t->unsignedBigInteger('port_id');
                $t->unsignedBigInteger('policy_id')->nullable();
                $t->string('severity', 16);
                $t->dateTime('started_at');
                $t->dateTime('triggered_at')->nullable();
                $t->dateTime('recovered_at')->nullable();
                $t->unsignedInteger('detect_seconds')->nullable();   // first_seen -> triggered (MTTA-ish)
                $t->unsignedInteger('duration_seconds')->nullable(); // first_seen -> recovered (MTTR)
                $t->unsignedInteger('notification_count')->default(0);
                $t->boolean('was_flapping')->default(false);
                $t->string('suppression_reason')->nullable();
                $t->timestamps();
                $t->index(['device_id', 'port_id']);
                $t->index(['policy_id', 'recovered_at']);
                $t->index('recovered_at');
                $t->index('started_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('iapm_outages');
        Schema::table('iapm_policies', function (Blueprint $t): void {
            foreach (['flap_settle_seconds', 'flap_window_seconds', 'flap_threshold', 'suppress_uplink_down'] as $column) {
                if (Schema::hasColumn('iapm_policies', $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
