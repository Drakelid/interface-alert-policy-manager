<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('iapm_incidents', 'recovery_action_due_at')) {
            Schema::table('iapm_incidents', fn (Blueprint $table) => $table->dateTime('recovery_action_due_at')->nullable());
        }
        if (! Schema::hasIndex('iapm_incidents', 'iapm_incident_recovery_due_idx')) {
            Schema::table('iapm_incidents', fn (Blueprint $table) => $table->index(['state', 'recovery_action_due_at', 'id'], 'iapm_incident_recovery_due_idx'));
        }
        // One upgrade discovery pass, then only indexed due markers are scanned.
        DB::table('iapm_incidents')->where('state', 'recovered')->whereNull('recovery_action_due_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('iapm_policy_actions')
                ->whereColumn('iapm_policy_actions.policy_id', 'iapm_incidents.policy_id')->where('phase', 'recovery'))
            ->update(['recovery_action_due_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasIndex('iapm_incidents', 'iapm_incident_recovery_due_idx')) {
            Schema::table('iapm_incidents', fn (Blueprint $table) => $table->dropIndex('iapm_incident_recovery_due_idx'));
        }
        if (Schema::hasColumn('iapm_incidents', 'recovery_action_due_at')) {
            Schema::table('iapm_incidents', fn (Blueprint $table) => $table->dropColumn('recovery_action_due_at'));
        }
    }
};
