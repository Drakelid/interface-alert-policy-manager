<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\DeviceGroup;
use App\Models\Location;
use App\Models\PortGroup;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ReceiverResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ReceiverPrecedenceTest extends IntegrationTestCase
{
    #[DataProvider('assignmentTypes')]
    public function test_every_winning_assignment_type_supplies_receivers(string $type): void
    {
        $location = Location::factory()->create();
        $device = $this->device(['location_id' => $location->id]);
        $port = $this->downPort($device, ['ifName' => 'xe-0/0/1', 'ifAlias' => 'CUST: one', 'ifType' => 'sonet']);
        $portGroup = PortGroup::factory()->create();
        $port->groups()->attach($portGroup->id);
        $deviceGroup = DeviceGroup::factory()->create();
        $device->groups()->attach($deviceGroup->id);
        $policy = $this->policy(['default_receiver' => 'policy']);
        $attributes = ['assignment_type' => $type, 'match_mode' => 'any', 'metadata_json' => ['receivers' => ['winner', 'winner']]];
        $attributes['assignment_reference'] = match ($type) {
            'port' => $port->port_id, 'port_group' => $portGroup->id, 'device' => $device->device_id, 'location' => $location->id, 'interface_type' => 'sonet', default => null
        };
        $attributes['match_expression'] = match ($type) {
            'ifalias_regex' => '/^CUST:/', 'ifname_regex' => '/^xe-/', default => null
        };
        $assignment = $policy->assignments()->create($attributes);
        if ($type === 'device_group') {
            $assignment->deviceGroups()->create(['device_group_id' => $deviceGroup->id]);
        }
        $resolution = app(PolicyResolver::class)->resolve(app(InterfaceContextService::class)->forPort($port), false);
        $action = $this->triggerAction($policy, $this->smsDestination(), ['receivers_json' => null]);

        self::assertSame(['winner'], app(ReceiverResolver::class)->forAction($action, $resolution));
    }

    public function test_losing_assignment_receivers_never_override_the_winner(): void
    {
        $device = $this->device();
        $port = $this->downPort($device);
        $loser = $this->policy();
        $loser->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $device->device_id, 'metadata_json' => ['receivers' => ['loser']]]);
        $winner = $this->policy();
        $winner->assignments()->create(['assignment_type' => 'port', 'assignment_reference' => $port->port_id, 'metadata_json' => ['receivers' => ['winner']]]);
        $resolution = app(PolicyResolver::class)->resolve(app(InterfaceContextService::class)->forPort($port), false);
        $action = $this->triggerAction($winner, $this->smsDestination());
        self::assertSame(['winner'], app(ReceiverResolver::class)->forAction($action, $resolution));
    }

    public static function assignmentTypes(): array
    {
        return array_map(fn ($type) => [$type], ['port', 'port_group', 'device', 'device_group', 'location', 'ifalias_regex', 'ifname_regex', 'interface_type', 'default']);
    }
}
