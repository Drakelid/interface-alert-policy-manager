<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class IngestionTest extends IntegrationTestCase
{
    public function test_an_authenticated_alert_creates_one_incident_per_fault(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $first = $this->downPort($device);
        $second = $this->downPort($device);

        $response = $this->ingest($this->alertPayload($device, [$this->fault($first), $this->fault($second)]));

        $response->assertOk()->assertJsonPath('counts.processed', 2)->assertJsonPath('counts.activated', 2);
        self::assertSame(2, Incident::count());
        self::assertSame("interface-down:{$device->device_id}:{$first->port_id}", Incident::where('port_id', $first->port_id)->value('incident_key'));
    }

    public function test_an_invalid_token_is_rejected_without_creating_incidents(): void
    {
        $this->defaultPolicy();
        $device = $this->device();

        $this->ingest($this->alertPayload($device, [$this->fault($this->downPort($device))]), 'wrong-token')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthorized');

        self::assertSame(0, Incident::count());
    }

    public function test_a_missing_token_is_rejected(): void
    {
        $this->ingest($this->alertPayload($this->device(), []), null)->assertUnauthorized();
    }

    public function test_a_rotated_token_is_accepted_during_the_overlap_window(): void
    {
        $this->defaultPolicy();
        $this->settings->put('previous_ingestion_token', 'old-token');
        $this->settings->put('previous_ingestion_token_expires_at', now()->addMinutes(10)->toIso8601String());
        $device = $this->device();

        $this->ingest($this->alertPayload($device, [$this->fault($this->downPort($device))]), 'old-token')->assertOk();
    }

    public function test_an_expired_previous_token_is_rejected(): void
    {
        $this->settings->put('previous_ingestion_token', 'old-token');
        $this->settings->put('previous_ingestion_token_expires_at', now()->subMinute()->toIso8601String());

        $this->ingest($this->alertPayload($this->device(), []), 'old-token')->assertUnauthorized();
    }

    public function test_a_malformed_payload_returns_structured_validation_errors(): void
    {
        $this->ingest(['state' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['device_id', 'faults']]]);
    }

    public function test_an_unsupported_alert_state_is_rejected_without_an_exception(): void
    {
        $device = $this->device();

        $this->ingest($this->alertPayload($device, [], 1, ['state' => 'exploded']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['state']]]);
    }

    public function test_string_identifiers_are_normalized(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);

        $payload = $this->alertPayload($device, [['port_id' => (string) $port->port_id]], 1, [
            'device_id' => (string) $device->device_id,
            'alert_id' => '572',
            'state' => '1',
            'alert_uid' => 18432,
        ]);

        $this->ingest($payload)->assertOk()->assertJsonPath('counts.processed', 1);
        self::assertSame('18432', Incident::first()->source_alert_uid);
    }

    public function test_a_repeated_identical_delivery_is_ignored(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $payload = $this->alertPayload($device, [$this->fault($this->downPort($device))]);

        $this->ingest($payload)->assertOk()->assertJsonPath('counts.processed', 1);
        $this->ingest($payload)->assertOk()->assertJsonPath('counts.ignored', 1);

        self::assertSame(1, Incident::count());
        self::assertSame(1, (int) Incident::first()->context_json['observation_count']);
    }

    public function test_a_port_belonging_to_another_device_is_counted_as_failed(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $other = $this->downPort($this->device());

        $this->ingest($this->alertPayload($device, [$this->fault($other)]))
            ->assertOk()
            ->assertJsonPath('counts.failed', 1)
            ->assertJsonPath('counts.processed', 0);

        self::assertSame(0, Incident::count());
    }

    public function test_a_fault_leaving_the_current_set_recovers_only_that_incident(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $recovering = $this->downPort($device);
        $stillDown = $this->downPort($device);

        $this->ingest($this->alertPayload($device, [$this->fault($recovering), $this->fault($stillDown)]))->assertOk();
        $this->ingest($this->alertPayload($device, [$this->fault($stillDown)], 1, ['timestamp' => now()->addMinute()->toIso8601String()]))
            ->assertOk()
            ->assertJsonPath('counts.recovered', 1);

        self::assertSame(IncidentState::Recovered, Incident::where('port_id', $recovering->port_id)->first()->state);
        self::assertSame(IncidentState::Active, Incident::where('port_id', $stillDown->port_id)->first()->state);
    }

    public function test_a_full_recovery_recovers_every_remaining_incident_for_the_source_alert(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $first = $this->downPort($device);
        $second = $this->downPort($device);

        $this->ingest($this->alertPayload($device, [$this->fault($first), $this->fault($second)]))->assertOk();
        $this->ingest($this->alertPayload($device, [], 0, ['timestamp' => now()->addMinute()->toIso8601String()]))
            ->assertOk()
            ->assertJsonPath('counts.recovered', 2);

        self::assertSame(0, Incident::where('state', '!=', IncidentState::Recovered)->count());
    }

    public function test_a_recovery_without_alert_correlation_is_refused(): void
    {
        $device = $this->device();

        $this->ingest(['device_id' => $device->device_id, 'state' => 0, 'faults' => []])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'correlation_required');
    }

    public function test_an_unknown_device_is_refused(): void
    {
        $this->ingest(['device_id' => 999999, 'state' => 1, 'faults' => []])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'device_not_found');
    }

    public function test_a_non_json_content_type_is_refused(): void
    {
        $this->post('/plugin/interface-alert-policy-manager/api/v1/alerts', ['state' => 1], [
            'Authorization' => 'Bearer test-ingestion-token',
            'Accept' => 'application/json',
        ])->assertStatus(415);
    }

    public function test_an_incident_stays_pending_until_the_trigger_delay_and_poll_count_are_met(): void
    {
        $this->defaultPolicy(['trigger_after_seconds' => 300, 'failed_poll_count' => 2]);
        $device = $this->device();
        $port = $this->downPort($device);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk()->assertJsonPath('counts.pending', 1);

        $incident = Incident::first();
        self::assertSame(IncidentState::Pending, $incident->state);
        self::assertNull($incident->triggered_at);
    }

    public function test_an_incident_recovering_before_activation_sends_no_trigger_notification(): void
    {
        $this->defaultPolicy(['trigger_after_seconds' => 300]);
        $device = $this->device();
        $port = $this->downPort($device);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();
        $this->ingest($this->alertPayload($device, [], 0, ['timestamp' => now()->addMinute()->toIso8601String()]))->assertOk();

        $incident = Incident::first();
        self::assertSame(IncidentState::Recovered, $incident->state);
        self::assertSame(0, (int) $incident->notification_count);
        self::assertSame(0, $incident->deliveries()->count());
        self::assertTrue($incident->events()->where('event_type', 'recovered')->exists());
    }

    public function test_an_upstream_acknowledgement_acknowledges_without_retriggering(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();
        self::assertSame(IncidentState::Active, Incident::first()->state);

        // LibreNMS re-sends the full fault set with an acknowledged state; this must
        // acknowledge the incident, not re-observe it as a fresh active alert.
        $this->ingest($this->alertPayload($device, [$this->fault($port)], \LibreNMS\Enum\AlertState::ACKNOWLEDGED, ['timestamp' => now()->addMinute()->toIso8601String()]))
            ->assertOk()
            ->assertJsonPath('counts.processed', 1);

        $incident = Incident::first();
        self::assertSame(IncidentState::Acknowledged, $incident->state);
        self::assertNotNull($incident->acknowledged_at);
        self::assertTrue($incident->events()->where('event_type', 'acknowledged')->exists());

        // A repeated acknowledgement is a no-op — it does not re-acknowledge or notify.
        $this->ingest($this->alertPayload($device, [$this->fault($port)], \LibreNMS\Enum\AlertState::ACKNOWLEDGED, ['timestamp' => now()->addMinutes(2)->toIso8601String()]))
            ->assertOk()
            ->assertJsonPath('counts.ignored', 1);

        self::assertSame(1, Incident::first()->events()->where('event_type', 'acknowledged')->count());
    }

    public function test_ingestion_does_not_write_the_policy_cache_on_the_hot_path(): void
    {
        $this->defaultPolicy();
        $device = $this->device();

        $this->ingest($this->alertPayload($device, [$this->fault($this->downPort($device))]))
            ->assertOk()
            ->assertJsonPath('counts.processed', 1);

        // Resolution still worked (an incident with a policy was created)...
        self::assertNotNull(Incident::first()->policy_id);
        // ...but the materialised policy cache is not written per-fault during ingestion;
        // the per-minute reconcile maintains it, keeping the storm hot path write-light.
        self::assertSame(0, \Illuminate\Support\Facades\DB::table('iapm_interface_policy_cache')->count());
    }

    public function test_an_interface_without_a_policy_is_recorded_and_suppressed(): void
    {
        $device = $this->device();

        $this->ingest($this->alertPayload($device, [$this->fault($this->downPort($device))]))
            ->assertOk()
            ->assertJsonPath('counts.suppressed', 1);

        $incident = Incident::first();
        self::assertSame(IncidentState::Suppressed, $incident->state);
        self::assertSame('no_policy', $incident->suppression_reason);
        self::assertNull($incident->policy_id);
    }
}
