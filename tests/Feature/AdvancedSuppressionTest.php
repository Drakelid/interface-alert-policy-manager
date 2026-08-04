<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\PortGroup;
use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class AdvancedSuppressionTest extends IntegrationTestCase
{
    public function test_a_down_uplink_suppresses_sibling_interfaces(): void
    {
        $group = PortGroup::factory()->create();
        $this->settings->put('uplink_port_group_id', $group->id);

        $device = $this->device();
        $uplink = $this->downPort($device);          // oper down, in the uplink group
        $uplink->groups()->attach($group->id);
        $customer = $this->downPort($device);         // sibling behind the uplink

        $this->defaultPolicy(['suppress_uplink_down' => true]);

        $this->ingest($this->alertPayload($device, [$this->fault($customer)]))
            ->assertOk()
            ->assertJsonPath('counts.suppressed', 1);

        self::assertSame('uplink_down', Incident::where('port_id', $customer->port_id)->first()->suppression_reason);
    }

    public function test_uplink_suppression_is_off_without_the_setting(): void
    {
        $device = $this->device();
        $uplink = $this->downPort($device);
        $customer = $this->downPort($device);
        $this->defaultPolicy(['suppress_uplink_down' => true]); // enabled on policy, but no group configured

        $this->ingest($this->alertPayload($device, [$this->fault($customer)]))
            ->assertOk()
            ->assertJsonPath('counts.activated', 1);

        self::assertNull(Incident::where('port_id', $customer->port_id)->first()->suppression_reason);
    }

    public function test_a_flapping_interface_is_dampened_to_a_single_notice(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy(['flap_threshold' => 3, 'flap_window_seconds' => 600, 'flap_settle_seconds' => 300]);
        $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));
        foreach (range(1, 3) as $i) {
            $incident->events()->create(['event_type' => 'reopened', 'event_message' => 'cycle']);
        }

        $this->artisan('iapm:process-actions');

        self::assertSame(1, DeliveryLog::where('phase', 'flapping')->count(), 'One dampened notice.');
        self::assertSame(0, DeliveryLog::where('phase', 'trigger')->count(), 'Routine trigger suppressed while flapping.');
        self::assertTrue($incident->events()->where('event_type', 'flapping')->exists());

        // A second pass does not send another notice.
        $this->artisan('iapm:process-actions');
        self::assertSame(1, DeliveryLog::where('phase', 'flapping')->count());
    }

    public function test_normal_notifications_resume_once_the_interface_settles(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy(['flap_threshold' => 3, 'flap_window_seconds' => 600, 'flap_settle_seconds' => 300]);
        $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));
        foreach (range(1, 3) as $i) {
            $incident->events()->create(['event_type' => 'reopened', 'event_message' => 'cycle']);
        }

        $this->artisan('iapm:process-actions'); // dampened

        $this->travel(700)->seconds(); // past the window and settle period
        $this->artisan('iapm:process-actions');

        self::assertTrue($incident->events()->where('event_type', 'flap_cleared')->exists());
        self::assertSame(1, DeliveryLog::where('phase', 'trigger')->count(), 'Routine trigger resumes after settling.');
    }
}
