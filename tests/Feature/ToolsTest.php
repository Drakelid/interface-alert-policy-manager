<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\User;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class ToolsTest extends IntegrationTestCase
{
    public function test_simulate_runs_a_synthetic_alert_through_ingestion(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);

        $this->actingAs($this->admin())
            ->post('/plugin/interface-alert-policy-manager/tools/simulate', ['port_id' => $port->port_id, 'state' => 'down'])
            ->assertOk()
            ->assertSee('accepted');

        self::assertSame(1, Incident::count());
        self::assertSame((int) $port->port_id, (int) Incident::first()->port_id);
    }

    public function test_simulated_up_recovers_the_same_incident_created_by_down(): void
    {
        $this->defaultPolicy(['trigger_after_seconds' => 0, 'down_observations' => 1, 'recovery_after_seconds' => 0]);
        $port = $this->downPort($this->device());
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/plugin/interface-alert-policy-manager/tools/simulate', ['port_id' => $port->port_id, 'state' => 'down'])
            ->assertOk();

        $incident = Incident::firstOrFail();
        self::assertSame('iapm-sim-port-'.$port->port_id, $incident->source_alert_uid);

        $this->actingAs($admin)
            ->post('/plugin/interface-alert-policy-manager/tools/simulate', ['port_id' => $port->port_id, 'state' => 'up'])
            ->assertOk()
            ->assertSee('recovered');

        self::assertSame('recovered', $incident->fresh()->state->value);
        self::assertSame(1, Incident::count(), 'Recovery must update the existing simulation incident, not create another one.');
    }

    public function test_simulate_requires_the_manage_ability(): void
    {
        $port = $this->downPort($this->device());

        $this->actingAs(User::factory()->create(['enabled' => true]))
            ->post('/plugin/interface-alert-policy-manager/tools/simulate', ['port_id' => $port->port_id, 'state' => 'down'])
            ->assertForbidden();
    }

    public function test_configuration_exports_and_reimports(): void
    {
        $admin = $this->admin();
        $destination = $this->smsDestination(['name' => 'GW']);
        $policy = $this->policy(['name' => 'Exported policy']);
        $this->triggerAction($policy, $destination);
        $policy->assignments()->create(['assignment_type' => 'default', 'match_mode' => 'any', 'priority' => 0, 'enabled' => true]);

        $json = $this->actingAs($admin)->get('/plugin/interface-alert-policy-manager/export')->assertOk()->streamedContent();

        // Remove the policy (and its dependents) then re-import.
        $policy->assignments()->delete();
        $policy->actions()->delete();
        $policy->delete();
        self::assertNull(Policy::where('name', 'Exported policy')->first());

        // P1-8 made import preview-then-apply: a POST without action=apply is a
        // dry run and writes nothing, so an import must now be confirmed.
        $this->actingAs($admin)
            ->post('/plugin/interface-alert-policy-manager/import', ['document' => $json, 'action' => 'apply'])
            ->assertOk();

        $imported = Policy::where('name', 'Exported policy')->first();
        self::assertNotNull($imported);
        self::assertSame(1, $imported->actions()->count(), 'Action recreated because destination "GW" still exists.');
        self::assertSame(1, $imported->assignments()->count());
    }

    public function test_import_skips_a_policy_that_already_exists(): void
    {
        $admin = $this->admin();
        $this->policy(['name' => 'Keep me']);
        $document = $this->actingAs($admin)->get('/plugin/interface-alert-policy-manager/export')->assertOk()->streamedContent();

        $this->actingAs($admin)
            ->post('/plugin/interface-alert-policy-manager/import', ['document' => $document])
            ->assertOk()
            ->assertSee('already exists');

        self::assertSame(1, Policy::where('name', 'Keep me')->count());
    }

    public function test_import_rejects_malformed_json(): void
    {
        $this->actingAs($this->admin())->post('/plugin/interface-alert-policy-manager/import', ['document' => '{broken'])->assertSessionHasErrors();
    }

    public function test_import_validates_regex_and_destination_before_writing_anything(): void
    {
        $admin = $this->admin();
        $destination = $this->smsDestination(['name' => 'Existing destination']);
        $policy = $this->policy(['name' => 'Source policy']);
        $this->triggerAction($policy, $destination);
        $policy->assignments()->create(['assignment_type' => 'ifname_regex', 'match_expression' => '/^xe-/', 'match_mode' => 'any', 'priority' => 0, 'enabled' => true]);
        $document = json_decode($this->actingAs($admin)->get('/plugin/interface-alert-policy-manager/export')->streamedContent(), true);
        $document['schedules'][] = ['name' => 'Must roll back', 'timezone' => 'UTC', 'enabled' => true, 'schedule_json' => ['mode' => 'always', 'days' => []]];
        $document['policies'][0]['name'] = 'New invalid policy';
        $document['policies'][0]['actions'][0]['destination'] = 'Missing destination';
        $document['policies'][0]['assignments'][0]['match_expression'] = '/[broken/';

        $this->actingAs($admin)->post('/plugin/interface-alert-policy-manager/import', ['document' => json_encode($document)])->assertSessionHasErrors();
        self::assertFalse(Schedule::where('name', 'Must roll back')->exists());
        self::assertFalse(Policy::where('name', 'New invalid policy')->exists());
    }
}
