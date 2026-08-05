<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\DeviceGroup;
use App\Models\Location;
use App\Models\Port;
use App\Models\PortGroup;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\AssignmentType;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class PolicyResolutionTest extends IntegrationTestCase
{
    public function test_the_most_specific_assignment_type_wins(): void
    {
        $device = $this->device();
        $port = $this->downPort($device);

        $fallback = $this->policy(['name' => 'Fallback']);
        $fallback->assignments()->create(['assignment_type' => 'default', 'match_mode' => 'any']);

        $deviceScoped = $this->policy(['name' => 'Device scoped']);
        $deviceScoped->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $device->device_id, 'match_mode' => 'any']);

        $portScoped = $this->policy(['name' => 'Port scoped']);
        $portScoped->assignments()->create(['assignment_type' => 'port', 'assignment_reference' => $port->port_id, 'match_mode' => 'any']);

        $resolution = $this->resolve($port);

        self::assertSame('Port scoped', $resolution->policy->name);
        self::assertSame(AssignmentType::Port, $resolution->winner->assignment_type);
        self::assertCount(3, $resolution->candidates, 'Every matching candidate is retained for explanation.');
    }

    public function test_a_higher_assignment_priority_wins_within_the_same_type(): void
    {
        $device = $this->device();
        $port = $this->downPort($device);

        $low = $this->policy(['name' => 'Low', 'priority' => 100]);
        $low->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $device->device_id, 'priority' => 1, 'match_mode' => 'any']);

        $high = $this->policy(['name' => 'High', 'priority' => 0]);
        $high->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $device->device_id, 'priority' => 50, 'match_mode' => 'any']);

        self::assertSame('High', $this->resolve($port)->policy->name);
    }

    public function test_policy_priority_breaks_an_assignment_priority_tie(): void
    {
        $device = $this->device();
        $port = $this->downPort($device);

        $low = $this->policy(['name' => 'Low policy priority', 'priority' => 1]);
        $low->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $device->device_id, 'priority' => 10, 'match_mode' => 'any']);

        $high = $this->policy(['name' => 'High policy priority', 'priority' => 9]);
        $high->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $device->device_id, 'priority' => 10, 'match_mode' => 'any']);

        self::assertSame('High policy priority', $this->resolve($port)->policy->name);
    }

    public function test_the_most_recently_updated_assignment_breaks_a_full_tie(): void
    {
        $device = $this->device();
        $port = $this->downPort($device);

        $older = $this->policy(['name' => 'Older', 'priority' => 5]);
        $older->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $device->device_id, 'priority' => 10, 'match_mode' => 'any', 'updated_at' => now()->subDay()]);

        $newer = $this->policy(['name' => 'Newer', 'priority' => 5]);
        $newer->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $device->device_id, 'priority' => 10, 'match_mode' => 'any', 'updated_at' => now()]);

        self::assertSame('Newer', $this->resolve($port)->policy->name);
    }

    public function test_device_group_any_matching(): void
    {
        [$first, $second] = [DeviceGroup::factory()->create(), DeviceGroup::factory()->create()];
        $device = $this->device();
        $device->groups()->attach($first->id);
        $port = $this->downPort($device);

        $policy = $this->policy(['name' => 'Any group']);
        $assignment = $policy->assignments()->create(['assignment_type' => 'device_group', 'match_mode' => 'any']);
        $assignment->deviceGroups()->createMany([['device_group_id' => $first->id], ['device_group_id' => $second->id]]);

        self::assertSame('Any group', $this->resolve($port)->policy->name);
    }

    public function test_device_group_all_matching_requires_every_group(): void
    {
        [$first, $second] = [DeviceGroup::factory()->create(), DeviceGroup::factory()->create()];
        $device = $this->device();
        $device->groups()->attach($first->id);
        $port = $this->downPort($device);

        $policy = $this->policy(['name' => 'All groups']);
        $assignment = $policy->assignments()->create(['assignment_type' => 'device_group', 'match_mode' => 'all']);
        $assignment->deviceGroups()->createMany([['device_group_id' => $first->id], ['device_group_id' => $second->id]]);

        self::assertNull($this->resolve($port)->policy);

        $device->groups()->attach($second->id);
        self::assertSame('All groups', $this->resolve($port->fresh())->policy->name);
    }

    public function test_device_group_exclude_matching(): void
    {
        [$excluded, $other] = [DeviceGroup::factory()->create(), DeviceGroup::factory()->create()];
        $device = $this->device();
        $device->groups()->attach($excluded->id);
        $port = $this->downPort($device);

        $policy = $this->policy(['name' => 'Everything except']);
        $assignment = $policy->assignments()->create(['assignment_type' => 'device_group', 'match_mode' => 'exclude']);
        $assignment->deviceGroups()->create(['device_group_id' => $excluded->id]);

        self::assertNull($this->resolve($port)->policy, 'A device inside an excluded group must not match.');

        $device->groups()->detach($excluded->id);
        $device->groups()->attach($other->id);
        self::assertSame('Everything except', $this->resolve($port->fresh())->policy->name);
    }

    public function test_a_device_group_assignment_with_no_groups_selected_never_matches(): void
    {
        $device = $this->device();
        $device->groups()->attach(DeviceGroup::factory()->create()->id);
        $port = $this->downPort($device);

        // An assignment saved without picking any group must not become a catch-all,
        // regardless of match mode (array_diff([], …) / array_intersect([], …) are []).
        foreach (['all', 'exclude', 'any'] as $mode) {
            $this->policy(['name' => "Empty {$mode}"])
                ->assignments()->create(['assignment_type' => 'device_group', 'match_mode' => $mode]);
        }

        self::assertNull($this->resolve($port)->policy, 'An empty device-group selection must match nothing.');
    }

    public function test_port_group_location_and_interface_type_assignments(): void
    {
        $location = Location::factory()->create();
        $device = $this->device(['location_id' => $location->id]);
        $port = $this->downPort($device, ['ifType' => 'sonet']);
        $portGroup = PortGroup::factory()->create();
        $port->groups()->attach($portGroup->id);

        $byType = $this->policy(['name' => 'By type']);
        $byType->assignments()->create(['assignment_type' => 'interface_type', 'assignment_reference' => 'sonet', 'match_mode' => 'any']);
        self::assertSame('By type', $this->resolve($port)->policy->name);

        $byLocation = $this->policy(['name' => 'By location']);
        $byLocation->assignments()->create(['assignment_type' => 'location', 'assignment_reference' => $location->id, 'match_mode' => 'any']);
        self::assertSame('By location', $this->resolve($port)->policy->name);

        $byPortGroup = $this->policy(['name' => 'By port group']);
        $byPortGroup->assignments()->create(['assignment_type' => 'port_group', 'assignment_reference' => $portGroup->id, 'match_mode' => 'any']);
        self::assertSame('By port group', $this->resolve($port)->policy->name);
    }

    public function test_regex_assignments_match_alias_and_name(): void
    {
        $device = $this->device();
        $port = $this->downPort($device, ['ifName' => 'xe-0/0/4', 'ifAlias' => 'CUST: Example customer - Primary']);

        $byName = $this->policy(['name' => 'By name']);
        $byName->assignments()->create(['assignment_type' => 'ifname_regex', 'match_expression' => '/^xe-/', 'match_mode' => 'any']);
        self::assertSame('By name', $this->resolve($port)->policy->name);

        $byAlias = $this->policy(['name' => 'By alias']);
        $byAlias->assignments()->create(['assignment_type' => 'ifalias_regex', 'match_expression' => '/^CUST:/', 'match_mode' => 'any']);
        self::assertSame('By alias', $this->resolve($port)->policy->name, 'ifAlias regex outranks ifName regex.');
    }

    public function test_an_invalid_regex_never_matches_and_never_raises(): void
    {
        $device = $this->device();
        $port = $this->downPort($device, ['ifName' => 'xe-0/0/4']);

        $policy = $this->policy(['name' => 'Broken regex']);
        $policy->assignments()->create(['assignment_type' => 'ifname_regex', 'match_expression' => '/[unterminated/', 'match_mode' => 'any']);

        self::assertNull($this->resolve($port)->policy);
    }

    public function test_an_over_long_regex_is_refused(): void
    {
        $device = $this->device();
        $port = $this->downPort($device);

        $policy = $this->policy(['name' => 'Huge regex']);
        $policy->assignments()->create(['assignment_type' => 'ifname_regex', 'match_expression' => '/'.str_repeat('a', 1001).'/', 'match_mode' => 'any']);

        self::assertNull($this->resolve($port)->policy);
    }

    public function test_disabled_policies_and_assignments_are_ignored(): void
    {
        $device = $this->device();
        $port = $this->downPort($device);

        $disabledPolicy = $this->policy(['name' => 'Disabled policy', 'enabled' => false]);
        $disabledPolicy->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $device->device_id, 'match_mode' => 'any']);

        $disabledAssignment = $this->policy(['name' => 'Disabled assignment']);
        $disabledAssignment->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $device->device_id, 'enabled' => false, 'match_mode' => 'any']);

        self::assertNull($this->resolve($port)->policy);
    }

    public function test_the_configured_default_policy_is_used_when_nothing_matches(): void
    {
        $port = $this->downPort($this->device());
        $policy = $this->policy(['name' => 'Configured default']);
        $this->settings->put('default_policy_id', $policy->id);

        $resolution = $this->resolve($port);

        self::assertSame('Configured default', $resolution->policy->name);
        self::assertNull($resolution->winner);
        self::assertSame('configured_default', DB::table('iapm_interface_policy_cache')->where('port_id', $port->port_id)->value('assignment_source'));
    }

    public function test_resolution_is_materialized_into_the_policy_cache(): void
    {
        $device = $this->device();
        $port = $this->downPort($device);
        $policy = $this->policy(['name' => 'Cached']);
        $assignment = $policy->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $device->device_id, 'match_mode' => 'any']);

        $this->resolve($port);

        $cached = DB::table('iapm_interface_policy_cache')->where('port_id', $port->port_id)->first();
        self::assertSame((int) $policy->id, (int) $cached->policy_id);
        self::assertSame((int) $assignment->id, (int) $cached->assignment_id);
        self::assertSame('device', $cached->assignment_source);
    }

    public function test_saving_a_policy_invalidates_the_cache(): void
    {
        $port = $this->downPort($this->device());
        $policy = $this->policy();
        $policy->assignments()->create(['assignment_type' => 'default', 'match_mode' => 'any']);
        $this->resolve($port);

        self::assertSame(1, DB::table('iapm_interface_policy_cache')->count());

        $policy->update(['priority' => 7]);

        self::assertSame(0, DB::table('iapm_interface_policy_cache')->count());
    }

    /** A fresh resolver per call: the resolver memoizes assignments for one batch. */
    private function resolve(Port $port)
    {
        return app()->make(PolicyResolver::class)->resolve(app(InterfaceContextService::class)->forPort($port->fresh()->load(['device.location', 'device.groups', 'groups'])));
    }
}
