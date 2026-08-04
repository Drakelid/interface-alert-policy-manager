<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\DeviceGroup;
use App\Models\Location;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AssignmentMatchCounter;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class AssignmentPreviewTest extends IntegrationTestCase
{
    private AssignmentMatchCounter $counter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->counter = app(AssignmentMatchCounter::class);
    }

    public function test_a_default_assignment_matches_every_live_port(): void
    {
        $device = $this->device();
        $this->downPort($device);
        $this->downPort($device);

        self::assertSame(2, $this->counter->count(['assignment_type' => 'default'])['count']);
    }

    public function test_a_device_assignment_counts_only_that_devices_ports(): void
    {
        $device = $this->device();
        $this->downPort($device);
        $this->downPort($this->device());

        self::assertSame(1, $this->counter->count(['assignment_type' => 'device', 'assignment_reference' => $device->device_id])['count']);
    }

    public function test_a_location_assignment_counts_ports_at_that_location(): void
    {
        $location = Location::factory()->create();
        $device = $this->device(['location_id' => $location->id]);
        $this->downPort($device);
        $this->downPort($this->device());

        self::assertSame(1, $this->counter->count(['assignment_type' => 'location', 'assignment_reference' => $location->id])['count']);
    }

    public function test_interface_type_matching(): void
    {
        $device = $this->device();
        $this->downPort($device, ['ifType' => 'sonet']);
        $this->downPort($device, ['ifType' => 'ethernetCsmacd']);

        self::assertSame(1, $this->counter->count(['assignment_type' => 'interface_type', 'assignment_reference' => 'sonet'])['count']);
    }

    public function test_device_group_any_and_exclude_counts(): void
    {
        [$a, $b] = [DeviceGroup::factory()->create(), DeviceGroup::factory()->create()];
        $inGroup = $this->device();
        $inGroup->groups()->attach($a->id);
        $this->downPort($inGroup);
        $outside = $this->device();
        $this->downPort($outside);

        self::assertSame(1, $this->counter->count(['assignment_type' => 'device_group', 'match_mode' => 'any', 'device_group_ids' => [$a->id, $b->id]])['count']);
        self::assertSame(1, $this->counter->count(['assignment_type' => 'device_group', 'match_mode' => 'exclude', 'device_group_ids' => [$a->id]])['count']);
    }

    public function test_a_regex_assignment_counts_matching_names(): void
    {
        $device = $this->device();
        $this->downPort($device, ['ifName' => 'xe-0/0/1']);
        $this->downPort($device, ['ifName' => 'ge-0/0/1']);

        $result = $this->counter->count(['assignment_type' => 'ifname_regex', 'match_expression' => '/^xe-/']);
        self::assertSame(1, $result['count']);
        self::assertFalse($result['capped']);
    }

    public function test_an_invalid_regex_reports_an_error_instead_of_a_count(): void
    {
        $result = $this->counter->count(['assignment_type' => 'ifname_regex', 'match_expression' => '/[unterminated/']);

        self::assertNotNull($result['error']);
        self::assertSame(0, $result['count']);
    }

    public function test_the_preview_endpoint_requires_the_assignment_ability(): void
    {
        $policy = $this->policy();

        $this->actingAs(\App\Models\User::factory()->create())
            ->post('/plugin/interface-alert-policy-manager/assignments/preview', ['policy_id' => $policy->id, 'assignment_type' => 'default', 'match_mode' => 'any', 'priority' => 0])
            ->assertForbidden();
    }

    public function test_the_preview_endpoint_returns_a_count_for_an_administrator(): void
    {
        $policy = $this->policy();
        $device = $this->device();
        $this->downPort($device);

        $this->actingAs($this->admin())
            ->postJson('/plugin/interface-alert-policy-manager/assignments/preview', ['policy_id' => $policy->id, 'assignment_type' => 'device', 'assignment_reference' => (string) $device->device_id, 'match_mode' => 'any', 'priority' => 0])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('capped', false);
    }
}
