<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\DeviceGroup;
use Illuminate\Validation\ValidationException;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AssignmentFormData;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AssignmentMatchCounter;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ConfigurationImportPlanner;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ConfigurationImportValidator;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class DeviceGroupExclusionTest extends IntegrationTestCase
{
    public function test_group_exclusions_match_the_preview_and_allow_other_policies_in_every_group_mode(): void
    {
        $groups = [DeviceGroup::factory()->create(), DeviceGroup::factory()->create()];
        $excluded = $this->device();
        $included = $this->device();
        $excludedPort = $this->downPort($excluded);
        $includedPort = $this->downPort($included);
        $fallback = $this->defaultPolicy();
        $policy = $this->policy();
        $assignment = $policy->assignments()->create([
            'assignment_type' => 'device_group', 'match_mode' => 'any',
            'metadata_json' => ['excluded_device_ids' => [$excluded->device_id]],
        ]);
        $ids = array_map(fn ($group) => $group->id, $groups);
        foreach ($ids as $id) {
            $assignment->deviceGroups()->create(['device_group_id' => $id]);
        }
        $resolve = fn ($port) => app(PolicyResolver::class)->resolve(app(InterfaceContextService::class)->forPort($port->fresh()->load(['device.location', 'device.groups', 'groups'])));

        foreach (['any', 'all', 'exclude'] as $mode) {
            $excluded->groups()->sync($mode === 'exclude' ? [] : $ids);
            $included->groups()->sync($mode === 'exclude' ? [] : $ids);
            $assignment->update(['match_mode' => $mode]);
            self::assertSame($fallback->id, $resolve($excludedPort)->policy->id, $mode);
            self::assertSame($assignment->id, $resolve($includedPort)->winner->id, $mode);
            self::assertNotContains($assignment->id, array_map(fn ($candidate) => $candidate->id, $resolve($excludedPort)->candidates));
            $preview = app(AssignmentMatchCounter::class)->count([
                'assignment_type' => 'device_group', 'match_mode' => $mode,
                'device_group_ids' => $ids, 'excluded_device_ids' => [$excluded->device_id],
            ]);
            self::assertSame(1, $preview['count']);
            self::assertSame(1, $preview['devices']);
            self::assertSame($includedPort->port_id, $preview['samples'][0]['port_id']);
        }

        $fallback->assignments()->delete();
        $this->settings->put('default_policy_id', $fallback->id);
        self::assertSame($fallback->id, $resolve($excludedPort)->policy->id);
        $this->ingest($this->alertPayload($excluded, [$this->fault($excludedPort)]))->assertOk()->assertJsonPath('counts.activated', 1);
        self::assertSame($fallback->id, Incident::where('port_id', $excludedPort->port_id)->value('policy_id'));
        $assignment->update(['metadata_json' => ['excluded_device_ids' => []]]);
        self::assertSame($policy->id, $resolve($excludedPort)->policy->id);
    }

    public function test_group_exclusions_round_trip_through_save_form_preview_and_removal(): void
    {
        $this->actingAs($this->admin());
        $policy = $this->policy();
        $group = DeviceGroup::factory()->create();
        $excluded = $this->device();
        $secondExcluded = $this->device();
        $included = $this->device();
        foreach ([$excluded, $secondExcluded, $included] as $device) {
            $device->groups()->attach($group->id);
            $this->downPort($device);
        }
        $payload = [
            'policy_id' => $policy->id, 'assignment_type' => 'device_group',
            'device_group_ids' => [$group->id], 'excluded_device_ids' => [(string) $excluded->device_id, (string) $secondExcluded->device_id],
            'match_mode' => 'any', 'priority' => 0, 'enabled' => '1', 'receivers_text' => 'noc',
        ];
        $this->post('/plugin/interface-alert-policy-manager/assignments', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $assignment = Assignment::sole();
        self::assertSame([$excluded->device_id, $secondExcluded->device_id], $assignment->metadata_json['excluded_device_ids']);
        self::assertSame(['noc'], $assignment->metadata_json['receivers']);
        $this->view('iapm::assignments.form', app(AssignmentFormData::class)->for($assignment) + ['policy' => $policy])
            ->assertSee('Excluded devices')->assertSee($excluded->hostname)->assertSee($secondExcluded->hostname)
            ->assertSee('name="excluded_device_ids[]" value="'.$excluded->device_id.'"', false);
        $this->post('/plugin/interface-alert-policy-manager/assignments/preview', $payload)->assertOk()->assertJsonPath('count', 1);
        unset($payload['excluded_device_ids']);
        $this->put('/plugin/interface-alert-policy-manager/assignments/'.$assignment->id, $payload)->assertSessionHasNoErrors()->assertRedirect();
        self::assertSame([], $assignment->fresh()->metadata_json['excluded_device_ids']);
        $this->post('/plugin/interface-alert-policy-manager/assignments/preview', $payload)->assertOk()->assertJsonPath('count', 3);
    }

    public function test_unknown_duplicate_and_malformed_excluded_devices_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $device = $this->device();
        $payload = ['policy_id' => $this->policy()->id, 'assignment_type' => 'device_group', 'match_mode' => 'any', 'priority' => 0];
        foreach ([[2147483647], [$device->device_id, $device->device_id], ['invalid']] as $ids) {
            $this->postJson('/plugin/interface-alert-policy-manager/assignments', $payload + ['excluded_device_ids' => $ids])
                ->assertUnprocessable()->assertJsonValidationErrors('excluded_device_ids.0');
        }
        self::assertSame(0, Assignment::count());
    }

    public function test_export_and_import_preserve_group_exclusions_and_validate_the_devices(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $device = $this->device();
        $policy = $this->policy();
        $group = DeviceGroup::factory()->create();
        $assignment = $policy->assignments()->create([
            'assignment_type' => 'device_group', 'match_mode' => 'any', 'priority' => 0, 'enabled' => true,
            'metadata_json' => ['excluded_device_ids' => [$device->device_id]],
        ]);
        $assignment->deviceGroups()->create(['device_group_id' => $group->id]);
        $document = json_decode($this->get('/plugin/interface-alert-policy-manager/export')->assertOk()->streamedContent(), true);
        $document['policies'][0]['name'] = 'Imported group exclusions';
        $validated = app(ConfigurationImportValidator::class)->validate($document);
        app(ConfigurationImportPlanner::class)->apply($validated, false, $admin->user_id);
        $copy = Assignment::where('policy_id', '!=', $policy->id)->sole();
        self::assertSame([$device->device_id], $copy->metadata_json['excluded_device_ids']);
        $document['policies'][0]['assignments'][0]['metadata_json']['excluded_device_ids'] = [2147483647];
        $this->expectException(ValidationException::class);
        app(ConfigurationImportValidator::class)->validate($document);
    }
}
