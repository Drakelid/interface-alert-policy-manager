<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\AssignmentFormData;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class DeviceExclusionTest extends IntegrationTestCase
{
    public function test_device_exclusions_override_port_assignments_and_the_configured_default(): void
    {
        $policy = $this->defaultPolicy();
        $this->settings->put('default_policy_id', $policy->id);
        $device = $this->device();
        $port = $this->downPort($device);
        $otherPort = $this->downPort($this->device());
        $policy->assignments()->create(['assignment_type' => 'port', 'assignment_reference' => $port->port_id, 'match_mode' => 'any']);
        $exclusion = $policy->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $device->device_id, 'match_mode' => 'exclude']);

        $resolve = fn ($p) => app(PolicyResolver::class)->resolve(app(InterfaceContextService::class)->forPort($p->load(['device.location', 'device.groups', 'groups'])));
        self::assertNull($resolve($port)->policy);
        self::assertSame($exclusion->id, $resolve($port)->winner->id);
        self::assertSame($policy->id, $resolve($otherPort)->policy->id);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk()->assertJsonPath('counts.activated', 0);
        self::assertNull(Incident::where('port_id', $port->port_id)->value('policy_id'));

        $exclusion->update(['enabled' => false]);
        self::assertSame($policy->id, $resolve($port)->policy->id);
        $exclusion->update(['enabled' => true]);
        $policy->update(['enabled' => false]);
        self::assertNull($resolve($port)->winner);
    }

    public function test_an_operator_can_save_edit_preview_and_remove_a_device_exclusion(): void
    {
        $policy = $this->policy();
        $device = $this->device();
        $this->downPort($device);
        $payload = ['policy_id' => $policy->id, 'assignment_type' => 'device', 'assignment_reference' => (string) $device->device_id, 'match_mode' => 'exclude', 'priority' => 0, 'enabled' => '1'];
        $this->actingAs($this->admin());
        $this->post('/plugin/interface-alert-policy-manager/assignments', $payload)->assertSessionHasNoErrors()->assertRedirect(route('iapm.policies.edit', $policy).'#assignments');
        $assignment = Assignment::sole();
        self::assertSame('exclude', $assignment->match_mode);
        $this->view('iapm::assignments.form', app(AssignmentFormData::class)->for($assignment) + ['policy' => $policy])
            ->assertSee('Exclude from alerts')->assertSee($device->hostname);
        $this->post('/plugin/interface-alert-policy-manager/assignments/preview', $payload)->assertOk()->assertJsonPath('count', 1);
        $this->put("/plugin/interface-alert-policy-manager/assignments/{$assignment->id}", array_replace($payload, ['match_mode' => 'any']))->assertSessionHasNoErrors()->assertRedirect();
        self::assertSame('any', $assignment->fresh()->match_mode);
        $this->delete("/plugin/interface-alert-policy-manager/assignments/{$assignment->id}")->assertRedirect();
        self::assertSame(0, Assignment::count());
    }

    public function test_a_device_exclusion_requires_an_existing_device(): void
    {
        $this->actingAs($this->admin())->postJson('/plugin/interface-alert-policy-manager/assignments', [
            'policy_id' => $this->policy()->id, 'assignment_type' => 'device', 'match_mode' => 'exclude', 'priority' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('assignment_reference');
    }
}
