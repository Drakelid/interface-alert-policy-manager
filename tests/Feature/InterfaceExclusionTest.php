<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\DeviceGroup;
use Illuminate\Validation\ValidationException;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AssignmentFormData;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AssignmentMatchCounter;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ConfigurationImportPlanner;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ConfigurationImportValidator;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class InterfaceExclusionTest extends IntegrationTestCase
{
    public function test_only_selected_interfaces_are_excluded_in_every_group_match_mode(): void
    {
        $group = DeviceGroup::factory()->create();
        $device = $this->device();
        $otherDevice = $this->device();
        $excluded = $this->downPort($device);
        $included = $this->downPort($device);
        $otherPort = $this->downPort($otherDevice);
        $fallback = $this->defaultPolicy();
        $policy = $this->policy();
        $assignment = $policy->assignments()->create([
            'assignment_type' => 'device_group', 'match_mode' => 'any',
            'metadata_json' => ['excluded_port_ids' => [$excluded->port_id], 'excluded_device_ids' => [$otherDevice->device_id]],
        ]);
        $assignment->deviceGroups()->create(['device_group_id' => $group->id]);
        $resolve = fn ($port) => app(PolicyResolver::class)->resolve(app(InterfaceContextService::class)->forPort($port->fresh()->load(['device.location', 'device.groups', 'groups'])));
        foreach (['any', 'all', 'exclude'] as $mode) {
            $device->groups()->sync($mode === 'exclude' ? [] : [$group->id]);
            $otherDevice->groups()->sync($mode === 'exclude' ? [] : [$group->id]);
            $assignment->update(['match_mode' => $mode]);
            self::assertSame($fallback->id, $resolve($excluded)->policy->id, $mode);
            self::assertSame($policy->id, $resolve($included)->policy->id, $mode);
            self::assertSame($fallback->id, $resolve($otherPort)->policy->id, $mode);
            $preview = app(AssignmentMatchCounter::class)->count([
                'assignment_type' => 'device_group', 'match_mode' => $mode, 'device_group_ids' => [$group->id],
                'excluded_port_ids' => [$excluded->port_id], 'excluded_device_ids' => [$otherDevice->device_id],
            ]);
            self::assertSame(1, $preview['count']);
            self::assertSame($included->port_id, $preview['samples'][0]['port_id']);
        }
    }

    public function test_interface_exclusions_survive_create_update_and_reopening_and_can_be_removed(): void
    {
        $this->actingAs($this->admin());
        $policy = $this->policy();
        $group = DeviceGroup::factory()->create();
        $device = $this->device();
        $device->groups()->attach($group->id);
        $first = $this->downPort($device);
        $second = $this->downPort($device);
        $payload = ['policy_id' => $policy->id, 'assignment_type' => 'device_group', 'device_group_ids' => [$group->id],
            'excluded_port_ids' => [(string) $first->port_id], 'match_mode' => 'any', 'priority' => 0, 'enabled' => '1'];
        $this->post('/plugin/interface-alert-policy-manager/assignments', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $assignment = Assignment::sole();
        self::assertSame([$first->port_id], $assignment->metadata_json['excluded_port_ids']);
        $payload['excluded_port_ids'][] = (string) $second->port_id;
        $this->put('/plugin/interface-alert-policy-manager/assignments/'.$assignment->id, $payload)->assertSessionHasNoErrors()->assertRedirect();
        $assignment->refresh();
        self::assertSame([$first->port_id, $second->port_id], $assignment->metadata_json['excluded_port_ids']);
        $this->view('iapm::assignments.form', app(AssignmentFormData::class)->for($assignment) + ['policy' => $policy])
            ->assertSee('Excluded interfaces')->assertSee($device->hostname)->assertSee($first->ifName)->assertSee($second->ifName)
            ->assertSee('name="excluded_port_ids[]" value="'.$first->port_id.'"', false);
        $this->post('/plugin/interface-alert-policy-manager/assignments/preview', $payload)->assertOk()->assertJsonPath('count', 0);
        unset($payload['excluded_port_ids']);
        $this->put('/plugin/interface-alert-policy-manager/assignments/'.$assignment->id, $payload)->assertSessionHasNoErrors()->assertRedirect();
        self::assertSame([], $assignment->fresh()->metadata_json['excluded_port_ids']);
        $this->post('/plugin/interface-alert-policy-manager/assignments/preview', $payload)->assertOk()->assertJsonPath('count', 2);
    }

    public function test_interfaces_whose_names_contain_an_excluded_word_are_skipped(): void
    {
        $this->actingAs($this->admin());
        $group = DeviceGroup::factory()->create();
        $device = $this->device();
        $device->groups()->attach($group->id);
        $byAlias = $this->downPort($device, ['ifAlias' => 'KUNDEPORT: Acme AS']);
        $byName = $this->downPort($device, ['ifName' => 'ge-0/0/kundeport-7', 'ifAlias' => '']);
        $byDescr = $this->downPort($device, ['ifDescr' => 'Kundeport 9', 'ifAlias' => '']);
        $included = $this->downPort($device, ['ifAlias' => 'Uplink core']);
        $fallback = $this->defaultPolicy();
        $policy = $this->policy();
        $payload = ['policy_id' => $policy->id, 'assignment_type' => 'device_group', 'device_group_ids' => [$group->id],
            'excluded_name_patterns_text' => " Kundeport \r\n\r\nkundeport\n", 'match_mode' => 'any', 'priority' => 0, 'enabled' => '1'];
        $this->post('/plugin/interface-alert-policy-manager/assignments', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $assignment = Assignment::where('policy_id', $policy->id)->sole();
        self::assertSame(['Kundeport'], $assignment->metadata_json['excluded_name_patterns']);

        $resolve = fn ($port) => app(PolicyResolver::class)->resolve(app(InterfaceContextService::class)->forPort($port->fresh()->load(['device.location', 'device.groups', 'groups'])));
        foreach ([$byAlias, $byName, $byDescr] as $port) {
            self::assertSame($fallback->id, $resolve($port)->policy->id, $port->ifName);
        }
        self::assertSame($policy->id, $resolve($included)->policy->id);

        $this->post('/plugin/interface-alert-policy-manager/assignments/preview', $payload)->assertOk()
            ->assertJsonPath('count', 1)->assertJsonPath('samples.0.port_id', $included->port_id);
        $this->view('iapm::assignments.form', app(AssignmentFormData::class)->for($assignment) + ['policy' => $policy])
            ->assertSee('Exclude interfaces by name')->assertSee('>Kundeport</textarea>', false);

        // A literal % must not act as a LIKE wildcard in the preview.
        $this->post('/plugin/interface-alert-policy-manager/assignments/preview', ['excluded_name_patterns_text' => '%'] + $payload)
            ->assertOk()->assertJsonPath('count', 4);

        $this->postJson('/plugin/interface-alert-policy-manager/assignments', ['excluded_name_patterns' => [str_repeat('x', 129)]] + $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('excluded_name_patterns.0');
    }

    public function test_the_picker_limits_interfaces_to_the_device_and_rejects_invalid_exclusions(): void
    {
        $this->actingAs($this->admin());
        $device = $this->device();
        $port = $this->downPort($device, ['ifName' => 'exclusion-test-interface']);
        $this->downPort($this->device(), ['ifName' => 'exclusion-test-interface']);
        $this->getJson('/plugin/interface-alert-policy-manager/lookup/ports?q=exclusion-test&device_id='.$device->device_id)
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $port->port_id);
        $payload = ['policy_id' => $this->policy()->id, 'assignment_type' => 'device_group', 'match_mode' => 'any', 'priority' => 0];
        foreach ([[2147483647], [$port->port_id, $port->port_id], ['invalid']] as $ids) {
            $this->postJson('/plugin/interface-alert-policy-manager/assignments', $payload + ['excluded_port_ids' => $ids])
                ->assertUnprocessable()->assertJsonValidationErrors('excluded_port_ids.0');
        }
        self::assertSame(0, Assignment::count());
    }

    public function test_export_and_import_preserve_interface_exclusions_and_validate_references(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $port = $this->downPort($this->device());
        $policy = $this->policy();
        $group = DeviceGroup::factory()->create();
        $assignment = $policy->assignments()->create([
            'assignment_type' => 'device_group', 'match_mode' => 'any', 'priority' => 0, 'enabled' => true,
            'metadata_json' => ['excluded_port_ids' => [$port->port_id]],
        ]);
        $assignment->deviceGroups()->create(['device_group_id' => $group->id]);
        $document = json_decode($this->get('/plugin/interface-alert-policy-manager/export')->assertOk()->streamedContent(), true);
        $document['policies'][0]['name'] = 'Imported interface exclusions';
        $validated = app(ConfigurationImportValidator::class)->validate($document);
        app(ConfigurationImportPlanner::class)->apply($validated, false, $admin->user_id);
        self::assertSame([$port->port_id], Assignment::where('policy_id', '!=', $policy->id)->sole()->metadata_json['excluded_port_ids']);
        $document['policies'][0]['assignments'][0]['metadata_json']['excluded_port_ids'] = [2147483647];
        $this->expectException(ValidationException::class);
        app(ConfigurationImportValidator::class)->validate($document);
    }
}
