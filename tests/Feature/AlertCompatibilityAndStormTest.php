<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LibreNMS\Enum\AlertState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
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

    public function test_a_representative_multi_interface_storm_is_bounded_and_complete(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $ports = collect(range(1, 250))->map(fn (int $index) => $this->downPort($device, [
            'ifName' => 'storm-'.$index,
            'ifAlias' => 'STORM: '.$index,
        ]));

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $this->ingest($this->alertPayload($device, $ports->map(fn ($port) => $this->fault($port))->all()))
            ->assertOk()
            ->assertJsonPath('counts.processed', 250)
            ->assertJsonPath('counts.activated', 250)
            ->assertJsonPath('counts.failed', 0);

        self::assertSame(250, Incident::count());
        self::assertSame(500, DB::table('iapm_incident_events')->count());
        self::assertSame(0, DB::table('iapm_interface_policy_cache')->count());
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
