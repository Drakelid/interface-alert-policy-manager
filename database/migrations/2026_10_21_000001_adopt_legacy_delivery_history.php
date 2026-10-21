<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Old payloads were redacted, so receiver identity cannot be recovered.
        // Associate only successes within the current episode's time range;
        // occurrence accounting deliberately retains their shared action budget.
        $cursor = 0;
        do {
            $ids = DB::table('iapm_delivery_logs')->where('id', '>', $cursor)
                ->whereNull('notification_outbox_id')->whereNull('episode_uuid')
                ->whereIn('status', ['sent', 'dry_run'])->orderBy('id')->limit(5000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $last = (int) $ids->last();
            DB::table('iapm_delivery_logs')->where('id', '>', $cursor)->where('id', '<=', $last)
                ->whereNull('notification_outbox_id')->whereNull('episode_uuid')
                ->whereIn('status', ['sent', 'dry_run'])
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('iapm_incidents')
                    ->whereColumn('iapm_incidents.id', 'iapm_delivery_logs.incident_id')
                    ->whereColumn('iapm_delivery_logs.created_at', '>=', 'iapm_incidents.first_seen_at'))
                ->update(['episode_uuid' => DB::raw('(SELECT episode_uuid FROM iapm_incidents WHERE iapm_incidents.id = iapm_delivery_logs.incident_id)')]);
            $cursor = $last;
        } while (true);
    }

    public function down(): void
    {
        // Restored episode correlation is historical data, not a schema change.
    }
};
