<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\AlertSchedule;
use LibreNMS\Enum\MaintenanceBehavior;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class SuppressionTest extends IntegrationTestCase
{
    public function test_a_down_device_suppresses_its_interface_incident(): void
    {
        $this->defaultPolicy();
        $device = $this->device(['status' => 0, 'status_reason' => 'icmp']);
        $port = $this->downPort($device);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk()->assertJsonPath('counts.suppressed', 1);

        $incident = Incident::sole();
        self::assertSame(IncidentState::Suppressed, $incident->state);
        self::assertSame('device_down', $incident->suppression_reason);
    }

    public function test_an_administratively_down_interface_is_suppressed(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device, ['ifAdminStatus' => 'down']);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();

        self::assertSame('admin_down', Incident::sole()->suppression_reason);
    }

    public function test_an_ignored_port_is_suppressed(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device, ['ignore' => 1]);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();

        self::assertSame('port_ignored', Incident::sole()->suppression_reason);
    }

    public function test_a_device_under_scheduled_maintenance_is_suppressed(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);

        $maintenance = AlertSchedule::factory()->create(['start' => now()->subHour(), 'end' => now()->addHour(), 'behavior' => MaintenanceBehavior::SkipAlerts]);
        $maintenance->devices()->attach($device->device_id);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();

        self::assertSame('scheduled_maintenance', Incident::sole()->suppression_reason);
    }

    public function test_a_maintenance_window_that_keeps_alerting_does_not_suppress(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);

        $maintenance = AlertSchedule::factory()->create(['start' => now()->subHour(), 'end' => now()->addHour(), 'behavior' => MaintenanceBehavior::RunAlerts]);
        $maintenance->devices()->attach($device->device_id);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk()->assertJsonPath('counts.activated', 1);

        self::assertNull(Incident::sole()->suppression_reason);
    }

    public function test_a_down_parent_device_suppresses_the_child_interface(): void
    {
        $this->defaultPolicy();
        $parent = $this->device(['status' => 0, 'status_reason' => 'icmp']);
        $device = $this->device();
        $device->parents()->attach($parent->device_id);
        $port = $this->downPort($device);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();

        self::assertSame('parent_down', Incident::sole()->suppression_reason);
    }

    public function test_a_policy_outside_its_schedule_is_suppressed(): void
    {
        $schedule = Schedule::create([
            'name' => 'Never',
            'timezone' => 'UTC',
            'enabled' => true,
            'schedule_json' => ['mode' => 'custom', 'days' => []],
        ]);
        $this->defaultPolicy(['business_schedule_id' => $schedule->id]);
        $device = $this->device();

        $this->ingest($this->alertPayload($device, [$this->fault($this->downPort($device))]))->assertOk();

        self::assertSame('outside_schedule', Incident::sole()->suppression_reason);
    }

    public function test_suppression_checks_can_be_disabled_per_policy(): void
    {
        $this->defaultPolicy(['suppress_device_down' => false]);
        $device = $this->device(['status' => 0, 'status_reason' => 'icmp']);

        $this->ingest($this->alertPayload($device, [$this->fault($this->downPort($device))]))
            ->assertOk()
            ->assertJsonPath('counts.activated', 1);

        self::assertNull(Incident::sole()->suppression_reason);
    }

    public function test_a_suppressed_incident_is_still_recorded_with_its_reason_and_timeline(): void
    {
        $this->defaultPolicy();
        $device = $this->device(['status' => 0, 'status_reason' => 'icmp']);

        $this->ingest($this->alertPayload($device, [$this->fault($this->downPort($device))]))->assertOk();

        $incident = Incident::sole();
        self::assertTrue($incident->events()->where('event_type', 'received')->exists());
        self::assertTrue($incident->events()->where('event_type', 'suppressed')->exists());
    }
}
