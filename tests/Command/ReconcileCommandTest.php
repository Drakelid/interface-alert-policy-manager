<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Command;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class ReconcileCommandTest extends IntegrationTestCase
{
    public function test_reconcile_preserves_an_acknowledgement_even_when_a_suppression_condition_applies(): void
    {
        // Device down would normally suppress the incident; but an operator has
        // acknowledged it, and reconcile must not bounce it through suppressed/active
        // (which is how an acknowledged incident used to "come back").
        $policy = $this->policy();
        $device = $this->device(['status' => 0]);
        $incident = $this->incident($policy, $this->downPort($device), ['state' => IncidentState::Acknowledged, 'acknowledged_at' => now()]);

        $this->artisan('iapm:reconcile')->assertExitCode(0);

        self::assertSame(IncidentState::Acknowledged, $incident->fresh()->state);
    }

    public function test_an_incident_whose_port_came_back_up_is_recovered(): void
    {
        $policy = $this->defaultPolicy();
        $port = $this->downPort($this->device());
        $incident = $this->incident($policy, $port);
        $port->update(['ifOperStatus' => 'up']);

        $this->artisan('iapm:reconcile')->assertExitCode(0);

        $incident->refresh();
        self::assertSame(IncidentState::Recovered, $incident->state);
        self::assertNotNull($incident->recovered_at);
        self::assertTrue($incident->events()->where('event_type', 'reconciled')->exists());
    }

    public function test_the_recovery_hold_down_delays_recovery(): void
    {
        $policy = $this->defaultPolicy(['recovery_after_seconds' => 600]);
        $port = $this->downPort($this->device());
        $incident = $this->incident($policy, $port);
        $port->update(['ifOperStatus' => 'up']);

        $this->artisan('iapm:reconcile')->assertExitCode(0);

        $incident->refresh();
        self::assertSame(IncidentState::Active, $incident->state);
        self::assertArrayHasKey('up_seen_at', $incident->context_json);

        // The hold-down has not elapsed, so a second pass must still not recover.
        $this->artisan('iapm:reconcile')->assertExitCode(0);
        self::assertSame(IncidentState::Active, $incident->fresh()->state);

        $this->travel(601)->seconds();
        $this->artisan('iapm:reconcile')->assertExitCode(0);
        self::assertSame(IncidentState::Recovered, $incident->fresh()->state);
    }

    public function test_a_pending_incident_activates_once_its_trigger_requirements_are_met(): void
    {
        $policy = $this->defaultPolicy(['trigger_after_seconds' => 300, 'failed_poll_count' => 1]);
        $port = $this->downPort($this->device());
        $incident = $this->incident($policy, $port, ['state' => IncidentState::Pending, 'triggered_at' => null, 'first_seen_at' => now()->subMinutes(10)]);

        $this->artisan('iapm:reconcile')->assertExitCode(0);

        $incident->refresh();
        self::assertSame(IncidentState::Active, $incident->state);
        self::assertNotNull($incident->triggered_at);
    }

    public function test_a_pending_incident_stays_pending_until_the_failed_poll_count_is_reached(): void
    {
        $policy = $this->defaultPolicy(['trigger_after_seconds' => 0, 'failed_poll_count' => 5]);
        $port = $this->downPort($this->device());
        $incident = $this->incident($policy, $port, ['state' => IncidentState::Pending, 'triggered_at' => null]);

        $this->artisan('iapm:reconcile')->assertExitCode(0);

        self::assertSame(IncidentState::Pending, $incident->fresh()->state);
        self::assertSame(2, (int) $incident->fresh()->context_json['observation_count']);
    }

    public function test_an_incident_is_suppressed_when_the_device_goes_down(): void
    {
        $policy = $this->defaultPolicy();
        $device = $this->device();
        $incident = $this->incident($policy, $this->downPort($device));
        $device->update(['status' => 0, 'status_reason' => 'icmp']);

        $this->artisan('iapm:reconcile')->assertExitCode(0);

        $incident->refresh();
        self::assertSame(IncidentState::Suppressed, $incident->state);
        self::assertSame('device_down', $incident->suppression_reason);
    }

    public function test_an_incident_is_unsuppressed_when_the_condition_clears(): void
    {
        $policy = $this->defaultPolicy();
        $incident = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Suppressed, 'suppression_reason' => 'device_down']);

        $this->artisan('iapm:reconcile')->assertExitCode(0);

        $incident->refresh();
        self::assertSame(IncidentState::Active, $incident->state);
        self::assertNull($incident->suppression_reason);
    }

    public function test_dry_run_reports_changes_without_applying_them(): void
    {
        $policy = $this->defaultPolicy();
        $port = $this->downPort($this->device());
        $incident = $this->incident($policy, $port);
        $port->update(['ifOperStatus' => 'up']);

        $this->artisan('iapm:reconcile --dry-run')
            ->expectsOutputToContain("Would set incident {$incident->id} to recovered")
            ->assertExitCode(0);

        self::assertSame(IncidentState::Active, $incident->fresh()->state);
        self::assertSame(0, $incident->events()->where('event_type', 'reconciled')->count());
    }

    public function test_reconciliation_can_be_limited_to_one_incident(): void
    {
        $policy = $this->defaultPolicy();
        $firstPort = $this->downPort($this->device());
        $secondPort = $this->downPort($this->device());
        $first = $this->incident($policy, $firstPort);
        $second = $this->incident($policy, $secondPort);
        $firstPort->update(['ifOperStatus' => 'up']);
        $secondPort->update(['ifOperStatus' => 'up']);

        $this->artisan("iapm:reconcile --incident={$first->id}")->assertExitCode(0);

        self::assertSame(IncidentState::Recovered, $first->fresh()->state);
        self::assertSame(IncidentState::Active, $second->fresh()->state);
    }

    public function test_a_deleted_port_recovers_or_is_retained_according_to_the_setting(): void
    {
        $policy = $this->defaultPolicy();
        $port = $this->downPort($this->device());
        $incident = $this->incident($policy, $port);
        $port->delete();

        $this->settings->put('deleted_port_behavior', 'retain');
        $this->artisan('iapm:reconcile')->assertExitCode(0);
        self::assertSame(IncidentState::Active, $incident->fresh()->state);

        $this->settings->put('deleted_port_behavior', 'recover');
        $this->artisan('iapm:reconcile')->assertExitCode(0);
        self::assertSame(IncidentState::Recovered, $incident->fresh()->state);
    }

    public function test_an_expired_mute_is_lifted(): void
    {
        $policy = $this->defaultPolicy();
        $incident = $this->incident($policy, $this->downPort($this->device()), ['muted_until' => now()->subMinute()]);

        $this->artisan('iapm:reconcile')->assertExitCode(0);

        self::assertNull($incident->fresh()->muted_until);
        self::assertTrue($incident->events()->where('event_type', 'unmuted')->exists());
    }
}
