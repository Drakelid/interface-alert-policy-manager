<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $addEpisode = ! Schema::hasColumn('iapm_incidents', 'episode_uuid');
        $addPreAcknowledgement = ! Schema::hasColumn('iapm_incidents', 'pre_acknowledgement_state');
        if ($addEpisode || $addPreAcknowledgement) {
            Schema::table('iapm_incidents', function (Blueprint $table) use ($addEpisode, $addPreAcknowledgement): void {
                if ($addEpisode) {
                    $table->uuid('episode_uuid')->nullable()->after('incident_key');
                }
                if ($addPreAcknowledgement) {
                    $table->string('pre_acknowledgement_state', 20)->nullable()->after('state');
                }
            });
        }
        if (! $this->hasIndex('iapm_incidents', 'iapm_incident_episode_state_idx')) {
            Schema::table('iapm_incidents', function (Blueprint $table): void {
                $table->index(['episode_uuid', 'state'], 'iapm_incident_episode_state_idx');
            });
        }

        DB::table('iapm_incidents')->whereNull('episode_uuid')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('iapm_incidents')->where('id', $row->id)->update(['episode_uuid' => (string) Str::uuid()]);
            }
        });

        if (Schema::hasTable('iapm_outages')) {
            if (! Schema::hasColumn('iapm_outages', 'episode_uuid')) {
                Schema::table('iapm_outages', function (Blueprint $table): void {
                    $table->uuid('episode_uuid')->nullable()->after('incident_id');
                });
            }
            DB::table('iapm_outages')->orderBy('id')->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    if ($row->episode_uuid === null) {
                        DB::table('iapm_outages')->where('id', $row->id)->update(['episode_uuid' => (string) Str::uuid()]);
                    }
                }
            });
            if (! $this->hasIndex('iapm_outages', 'iapm_outage_incident_episode_unique')) {
                Schema::table('iapm_outages', function (Blueprint $table): void {
                    $table->unique(['incident_id', 'episode_uuid'], 'iapm_outage_incident_episode_unique');
                });
            }
        }

        if (! Schema::hasTable('iapm_notification_outbox')) {
            Schema::create('iapm_notification_outbox', function (Blueprint $table): void {
                $table->id();
                $table->char('idempotency_key', 64)->unique();
                $table->uuid('episode_uuid')->nullable();
                $table->foreignId('incident_id')->nullable()->constrained('iapm_incidents')->cascadeOnDelete();
                $table->foreignId('destination_id')->constrained('iapm_destinations')->restrictOnDelete();
                $table->foreignId('policy_action_id')->nullable()->constrained('iapm_policy_actions')->nullOnDelete();
                $table->string('phase', 24);
                $table->char('receiver_hash', 64);
                $table->text('receiver_encrypted');
                $table->text('message_encrypted');
                $table->text('incident_ids_encrypted');
                $table->string('status', 24)->default('pending');
                $table->unsignedInteger('attempt_count')->default(0);
                $table->dateTime('available_at')->nullable();
                $table->dateTime('claimed_at')->nullable();
                $table->dateTime('delivered_at')->nullable();
                $table->text('last_error_redacted')->nullable();
                $table->timestamps();
                $table->index(['status', 'available_at'], 'iapm_outbox_status_available_idx');
                $table->index(['incident_id', 'episode_uuid', 'phase'], 'iapm_outbox_episode_phase_idx');
            });
        }
        if (! Schema::hasTable('iapm_notification_outbox_incidents')) {
            Schema::create('iapm_notification_outbox_incidents', function (Blueprint $table): void {
                $table->unsignedBigInteger('notification_outbox_id');
                $table->unsignedBigInteger('incident_id');
                $table->uuid('episode_uuid');
                $table->primary(['notification_outbox_id', 'incident_id'], 'iapm_outbox_incident_primary');
                $table->index(['incident_id', 'episode_uuid'], 'iapm_outbox_incident_episode_idx');
                $table->foreign('notification_outbox_id', 'iapm_outbox_incident_outbox_fk')->references('id')->on('iapm_notification_outbox')->cascadeOnDelete();
                $table->foreign('incident_id', 'iapm_outbox_incident_incident_fk')->references('id')->on('iapm_incidents')->cascadeOnDelete();
            });
        }

        $deliveryColumns = [
            'notification_outbox_id' => fn (Blueprint $table) => $table->unsignedBigInteger('notification_outbox_id')->nullable()->after('id'),
            'episode_uuid' => fn (Blueprint $table) => $table->uuid('episode_uuid')->nullable()->after('incident_id'),
            'logical_notification_key' => fn (Blueprint $table) => $table->char('logical_notification_key', 64)->nullable()->after('phase'),
            'receiver_hash' => fn (Blueprint $table) => $table->char('receiver_hash', 64)->nullable()->after('logical_notification_key'),
        ];
        foreach ($deliveryColumns as $column => $definition) {
            if (! Schema::hasColumn('iapm_delivery_logs', $column)) {
                Schema::table('iapm_delivery_logs', $definition);
            }
        }
        if (! $this->hasIndex('iapm_delivery_logs', 'iapm_delivery_episode_phase_status_idx')) {
            Schema::table('iapm_delivery_logs', function (Blueprint $table): void {
                $table->index(['episode_uuid', 'phase', 'status'], 'iapm_delivery_episode_phase_status_idx');
            });
        }
        if (! $this->hasIndex('iapm_delivery_logs', 'iapm_delivery_outbox_idx')) {
            Schema::table('iapm_delivery_logs', function (Blueprint $table): void {
                $table->index('notification_outbox_id', 'iapm_delivery_outbox_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('iapm_delivery_logs', 'iapm_delivery_episode_phase_status_idx')) {
            Schema::table('iapm_delivery_logs', function (Blueprint $table): void {
                $table->dropIndex('iapm_delivery_episode_phase_status_idx');
            });
        }
        if ($this->hasIndex('iapm_delivery_logs', 'iapm_delivery_outbox_idx')) {
            Schema::table('iapm_delivery_logs', function (Blueprint $table): void {
                $table->dropIndex('iapm_delivery_outbox_idx');
            });
        }
        $deliveryColumns = collect(['notification_outbox_id', 'episode_uuid', 'logical_notification_key', 'receiver_hash'])->filter(fn (string $column) => Schema::hasColumn('iapm_delivery_logs', $column))->all();
        if ($deliveryColumns !== []) {
            Schema::table('iapm_delivery_logs', function (Blueprint $table) use ($deliveryColumns): void {
                $table->dropColumn($deliveryColumns);
            });
        }
        Schema::dropIfExists('iapm_notification_outbox_incidents');
        Schema::dropIfExists('iapm_notification_outbox');
        if (Schema::hasTable('iapm_outages') && Schema::hasColumn('iapm_outages', 'episode_uuid')) {
            $hasOutageUnique = $this->hasIndex('iapm_outages', 'iapm_outage_incident_episode_unique');
            Schema::table('iapm_outages', function (Blueprint $table) use ($hasOutageUnique): void {
                if ($hasOutageUnique) {
                    $table->dropUnique('iapm_outage_incident_episode_unique');
                }
                $table->dropColumn('episode_uuid');
            });
        }
        if (Schema::hasColumn('iapm_incidents', 'episode_uuid') || Schema::hasColumn('iapm_incidents', 'pre_acknowledgement_state')) {
            $hasEpisodeIndex = $this->hasIndex('iapm_incidents', 'iapm_incident_episode_state_idx');
            $columns = collect(['episode_uuid', 'pre_acknowledgement_state'])->filter(fn (string $column) => Schema::hasColumn('iapm_incidents', $column))->all();
            Schema::table('iapm_incidents', function (Blueprint $table) use ($hasEpisodeIndex, $columns): void {
                if ($hasEpisodeIndex) {
                    $table->dropIndex('iapm_incident_episode_state_idx');
                }
                $table->dropColumn($columns);
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index) => ($index['name'] ?? null) === $name);
    }
};
