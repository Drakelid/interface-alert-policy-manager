<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LibreNMS\Enum\AlertState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\IngestionInbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Outage;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AlertCompatibilityAndStormTest extends IntegrationTestCase
{
    #[DataProvider('activeLibreNmsStates')]
    public function test_every_active_librenms_state_is_accepted_by_the_real_endpoint(int $state): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);

        $this->ingest($this->alertPayload($device, [$this->fault($port)], $state))
            ->assertOk()
            ->assertJsonPath('counts.processed', 1)
            ->assertJsonPath('counts.activated', 1);

        self::assertSame(IncidentState::Active, Incident::sole()->state);
    }

    public function test_all_librenms_state_transitions_preserve_acknowledgement_and_close_one_episode(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);
        $minute = 0;

        foreach ([AlertState::ACTIVE, AlertState::WORSE, AlertState::BETTER, AlertState::CHANGED] as $state) {
            $this->ingest($this->alertPayload($device, [$this->fault($port)], $state, [
                'timestamp' => now()->addMinutes($minute++)->toIso8601String(),
            ]))->assertOk();
        }

        $incident = Incident::sole();
        $episode = $incident->episode_uuid;
        self::assertSame(4, (int) $incident->context_json['observation_count']);
        self::assertSame(IncidentState::Active, $incident->state);

        $this->ingest($this->alertPayload($device, [$this->fault($port)], AlertState::ACKNOWLEDGED, [
            'timestamp' => now()->addMinutes($minute++)->toIso8601String(),
        ]))->assertOk();
        self::assertSame(IncidentState::Acknowledged, $incident->fresh()->state);

        // LibreNMS can emit WORSE/BETTER/CHANGED again while an alert remains
        // acknowledged. None may resurrect it or create a new episode.
        foreach ([AlertState::WORSE, AlertState::BETTER, AlertState::CHANGED] as $state) {
            $this->ingest($this->alertPayload($device, [$this->fault($port)], $state, [
                'timestamp' => now()->addMinutes($minute++)->toIso8601String(),
            ]))->assertOk()->assertJsonPath('counts.ignored', 1);
        }

        $this->ingest($this->alertPayload($device, [], AlertState::RECOVERED, [
            'timestamp' => now()->addMinutes($minute)->toIso8601String(),
        ]))->assertOk()->assertJsonPath('counts.recovered', 1);

        $incident = $incident->fresh();
        self::assertSame($episode, $incident->episode_uuid);
        self::assertSame(IncidentState::Recovered, $incident->state);
        self::assertSame(1, $incident->events()->where('event_type', 'acknowledged')->count());
        self::assertSame(1, $incident->events()->where('event_type', 'recovered')->count());
        self::assertSame(1, Outage::where('incident_id', $incident->id)->where('episode_uuid', $episode)->count());
    }

    public function test_duplicate_faults_in_one_webhook_are_idempotent(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);
        $fault = $this->fault($port);

        $this->ingest($this->alertPayload($device, [$fault, $fault, $fault]))
            ->assertOk()
            ->assertJsonPath('counts.processed', 1)
            ->assertJsonPath('counts.ignored', 2);

        self::assertSame(1, Incident::count());
        self::assertSame(2, Incident::sole()->events()->count());
    }

    public function test_recovery_requires_all_supplied_source_identifiers_to_match(): void
    {
        $policy = $this->defaultPolicy();
        $device = $this->device();
        $first = $this->incident($policy, $this->downPort($device), ['source_alert_id' => 1001, 'source_alert_uid' => 'uid-a', 'source_rule_id' => 501]);
        $second = $this->incident($policy, $this->downPort($device), ['source_alert_id' => 1002, 'source_alert_uid' => 'uid-b', 'source_rule_id' => 502]);

        // This deliberately crosses identifiers from two alerts. OR correlation
        // would recover both unrelated interfaces; conjunction must recover none.
        $this->ingest($this->alertPayload($device, [], AlertState::RECOVERED, [
            'alert_id' => 1001,
            'alert_uid' => 'uid-b',
            'rule_id' => 501,
        ]))->assertOk()->assertJsonPath('counts.recovered', 0);
        self::assertSame(IncidentState::Active, $first->fresh()->state);
        self::assertSame(IncidentState::Active, $second->fresh()->state);

        $this->ingest($this->alertPayload($device, [], AlertState::RECOVERED, [
            'alert_id' => 1001,
            'alert_uid' => 'uid-a',
            'rule_id' => 502,
        ]))->assertOk()->assertJsonPath('counts.recovered', 0);

        $this->ingest($this->alertPayload($device, [], AlertState::RECOVERED, [
            'alert_id' => 1001,
            'alert_uid' => 'uid-a',
            'rule_id' => 501,
            'timestamp' => now()->addSecond()->toIso8601String(),
        ]))->assertOk()->assertJsonPath('counts.recovered', 1);
        self::assertSame(IncidentState::Recovered, $first->fresh()->state);
        self::assertSame(IncidentState::Active, $second->fresh()->state);
    }

    public function test_delayed_source_events_cannot_reverse_a_newer_transition(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);
        $base = now()->startOfSecond();

        $this->ingest($this->alertPayload($device, [$this->fault($port)], AlertState::ACTIVE, [
            'timestamp' => $base->toIso8601String(),
        ]))->assertOk();
        $this->ingest($this->alertPayload($device, [], AlertState::RECOVERED, [
            'timestamp' => $base->copy()->addMinutes(2)->toIso8601String(),
        ]))->assertOk()->assertJsonPath('counts.recovered', 1);

        // A delayed active observation from between those events must not reopen.
        $this->ingest($this->alertPayload($device, [$this->fault($port)], AlertState::ACTIVE, [
            'timestamp' => $base->copy()->addMinute()->toIso8601String(),
        ]))->assertOk()->assertJsonPath('counts.ignored', 1);
        self::assertSame(IncidentState::Recovered, Incident::sole()->state);
        self::assertSame(1, Outage::count());

        // A genuinely newer observation starts a new episode, while the older
        // recovery remains unable to close it.
        $this->ingest($this->alertPayload($device, [$this->fault($port)], AlertState::ACTIVE, [
            'timestamp' => $base->copy()->addMinutes(3)->toIso8601String(),
        ]))->assertOk()->assertJsonPath('counts.activated', 1);
        $this->ingest($this->alertPayload($device, [], AlertState::RECOVERED, [
            'timestamp' => $base->copy()->addMinutes(2)->toIso8601String(),
        ]))->assertOk()->assertJsonPath('counts.recovered', 0);
        self::assertSame(IncidentState::Active, Incident::sole()->state);
    }

    public function test_a_representative_multi_interface_storm_is_bounded_and_complete(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $ports = collect(range(1, 250))->map(fn (int $index) => $this->downPort($device, [
            'ifName' => 'storm-'.$index,
            'ifAlias' => 'STORM: '.$index,
        ]));

        $queries = 0;
        $portReads = 0;
        DB::listen(static function ($query) use (&$queries, &$portReads): void {
            $queries++;
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains(strtolower($query->sql), 'from `ports`')) {
                $portReads++;
            }
        });

        $this->ingest($this->alertPayload($device, $ports->map(fn ($port) => $this->fault($port))->all()))
            ->assertOk()
            ->assertJsonPath('counts.processed', 250)
            ->assertJsonPath('counts.activated', 250)
            ->assertJsonPath('counts.failed', 0);

        self::assertSame(250, Incident::count());
        self::assertSame(500, DB::table('iapm_incident_events')->count());
        self::assertSame(0, DB::table('iapm_interface_policy_cache')->count());
        self::assertLessThanOrEqual(2, $portReads, "Storm ingestion issued {$portReads} port reads; expected a bulk lookup.");
        // The unavoidable incident/event writes are linear. This guard catches
        // accidental resolver or relationship N+1 explosions beyond that budget.
        self::assertLessThanOrEqual(2500, $queries, "Storm ingestion issued {$queries} SQL queries.");
    }

    public function test_payloads_over_the_fault_limit_are_rejected_before_processing(): void
    {
        $device = $this->device();
        $faults = array_fill(0, 10001, ['port_id' => 1]);

        $this->ingest($this->alertPayload($device, $faults))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['faults']]]);

        self::assertSame(0, Incident::count());
    }

    public function test_large_webhook_is_durably_accepted_encrypted_and_replayed_once(): void
    {
        config(['iapm.ingestion.async_threshold' => 3]);
        $this->defaultPolicy();
        $device = $this->device();
        $ports = collect(range(1, 3))->map(fn (int $index) => $this->downPort($device, ['ifName' => 'durable-'.$index]));
        $payload = $this->alertPayload($device, $ports->map(fn ($port) => $this->fault($port))->all());

        $this->ingest($payload)->assertStatus(202)->assertJsonPath('processing', 'durable_inbox');
        $this->ingest($payload)->assertStatus(202);

        self::assertSame(1, IngestionInbox::count());
        self::assertSame(0, Incident::count());
        self::assertStringNotContainsString('faults', (string) IngestionInbox::sole()->getRawOriginal('payload_encrypted'));

        $this->artisan('iapm:drain-ingestion')->assertExitCode(0);
        self::assertSame('processed', IngestionInbox::sole()->status);
        self::assertSame(3, Incident::count());
        self::assertSame(6, DB::table('iapm_incident_events')->count());

        $this->artisan('iapm:drain-ingestion')->assertExitCode(0);
        self::assertSame(3, Incident::count());
    }

    public function test_ten_thousand_fault_webhook_is_accepted_only_after_durable_storage(): void
    {
        config(['iapm.ingestion.async_threshold' => 1000]);
        $device = $this->device();
        $faults = array_map(fn (int $portId) => ['port_id' => $portId], range(1, 10000));

        $this->ingest($this->alertPayload($device, $faults))
            ->assertStatus(202)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('processing', 'durable_inbox');

        $row = IngestionInbox::sole();
        self::assertSame(10000, $row->fault_count);
        self::assertSame('pending', $row->status);
        self::assertCount(10000, $row->payload_encrypted['faults']);
    }

    public function test_whole_device_recovery_is_durably_accepted_before_bulk_replay(): void
    {
        config(['iapm.ingestion.async_recovery' => true]);
        $policy = $this->defaultPolicy();
        $device = $this->device();
        $incidents = collect(range(1, 10))->map(fn () => $this->incident($policy, $this->downPort($device)));

        $this->ingest($this->alertPayload($device, [], AlertState::RECOVERED))
            ->assertStatus(202)
            ->assertJsonPath('processing', 'durable_inbox');
        self::assertSame(10, Incident::where('state', IncidentState::Active)->count());

        $this->artisan('iapm:drain-ingestion')->assertExitCode(0);
        self::assertSame(10, Incident::where('state', IncidentState::Recovered)->count());
        self::assertSame(10, Outage::whereIn('incident_id', $incidents->pluck('id'))->count());
    }

    public function test_full_durable_inbox_returns_explicit_retryable_backpressure(): void
    {
        config(['iapm.ingestion.async_threshold' => 1, 'iapm.ingestion.inbox_max_pending' => 1]);
        $device = $this->device();

        $this->ingest($this->alertPayload($device, [['port_id' => 1]], overrides: ['timestamp' => now()->toIso8601String()]))->assertStatus(202);
        $this->ingest($this->alertPayload($device, [['port_id' => 2]], overrides: ['timestamp' => now()->addSecond()->toIso8601String()]))
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('error.code', 'ingestion_backlog_full');

        self::assertSame(1, IngestionInbox::count());
    }

    public function test_oversized_json_is_rejected_before_authentication_or_validation(): void
    {
        config(['iapm.ingestion.max_bytes' => 32]);

        $this->withServerVariables(['CONTENT_LENGTH' => '1024'])
            ->ingest($this->alertPayload($this->device(), []))
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'payload_too_large');
    }

    public function test_non_interface_faults_are_rejected_without_partial_processing(): void
    {
        $device = $this->device();

        $this->ingest($this->alertPayload($device, [['sensor_id' => 1234]]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['faults.0.port_id']]]);

        self::assertSame(0, Incident::count());
    }

    public static function activeLibreNmsStates(): array
    {
        return [
            'active' => [AlertState::ACTIVE],
            'worse' => [AlertState::WORSE],
            'better' => [AlertState::BETTER],
            'changed' => [AlertState::CHANGED],
        ];
    }
}
