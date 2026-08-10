<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cover the episode/action delivery probes, flap window lookup, and stale outbox
 * claim recovery used on every scheduled pass. These are additive indexes so an
 * existing installation can roll forward without rewriting historical values.
 */
return new class extends Migration
{
    public function up(): void
    {
        $alert = ! $this->hasIndex('iapm_incidents', 'iapm_incident_alert_device_state_idx');
        $uid = ! $this->hasIndex('iapm_incidents', 'iapm_incident_uid_device_state_idx');
        $rule = ! $this->hasIndex('iapm_incidents', 'iapm_incident_rule_device_state_idx');
        if ($alert || $uid || $rule) {
            Schema::table('iapm_incidents', function (Blueprint $table) use ($alert, $uid, $rule): void {
                if ($alert) {
                    $table->index(['source_alert_id', 'device_id', 'state'], 'iapm_incident_alert_device_state_idx');
                }
                if ($uid) {
                    $table->index(['source_alert_uid', 'device_id', 'state'], 'iapm_incident_uid_device_state_idx');
                }
                if ($rule) {
                    $table->index(['source_rule_id', 'device_id', 'state'], 'iapm_incident_rule_device_state_idx');
                }
            });
        }
        if (! $this->hasIndex('iapm_delivery_logs', 'iapm_delivery_action_episode_idx')) {
            Schema::table('iapm_delivery_logs', function (Blueprint $table): void {
                $table->index(['incident_id', 'episode_uuid', 'policy_action_id', 'phase', 'status'], 'iapm_delivery_action_episode_idx');
            });
        }
        if (! $this->hasIndex('iapm_incident_events', 'iapm_event_incident_type_time_idx')) {
            Schema::table('iapm_incident_events', function (Blueprint $table): void {
                $table->index(['incident_id', 'event_type', 'created_at'], 'iapm_event_incident_type_time_idx');
            });
        }
        $finalized = ! Schema::hasColumn('iapm_notification_outbox', 'finalized_at');
        $claimedIndex = ! $this->hasIndex('iapm_notification_outbox', 'iapm_outbox_status_claimed_idx');
        $finalizedIndex = ! $this->hasIndex('iapm_notification_outbox', 'iapm_outbox_status_finalized_idx');
        $logicalIndex = ! $this->hasIndex('iapm_notification_outbox', 'iapm_outbox_logical_lookup_idx');
        if ($finalized || $claimedIndex || $finalizedIndex || $logicalIndex) {
            Schema::table('iapm_notification_outbox', function (Blueprint $table) use ($finalized, $claimedIndex, $finalizedIndex, $logicalIndex): void {
                if ($finalized) {
                    $table->dateTime('finalized_at')->nullable()->after('delivered_at');
                }
                if ($claimedIndex) {
                    $table->index(['status', 'claimed_at'], 'iapm_outbox_status_claimed_idx');
                }
                if ($finalizedIndex) {
                    $table->index(['status', 'finalized_at'], 'iapm_outbox_status_finalized_idx');
                }
                if ($logicalIndex) {
                    $table->index(['incident_id', 'episode_uuid', 'policy_action_id', 'phase', 'receiver_hash', 'status'], 'iapm_outbox_logical_lookup_idx');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'iapm_notification_outbox' => ['iapm_outbox_status_claimed_idx', 'iapm_outbox_status_finalized_idx', 'iapm_outbox_logical_lookup_idx'],
            'iapm_incident_events' => ['iapm_event_incident_type_time_idx'],
            'iapm_delivery_logs' => ['iapm_delivery_action_episode_idx'],
            'iapm_incidents' => ['iapm_incident_alert_device_state_idx', 'iapm_incident_uid_device_state_idx', 'iapm_incident_rule_device_state_idx'],
        ] as $tableName => $indexes) {
            foreach ($indexes as $index) {
                if ($this->hasIndex($tableName, $index)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($index));
                }
            }
        }
        if (Schema::hasColumn('iapm_notification_outbox', 'finalized_at')) {
            Schema::table('iapm_notification_outbox', fn (Blueprint $table) => $table->dropColumn('finalized_at'));
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index) => ($index['name'] ?? null) === $name);
    }
};
