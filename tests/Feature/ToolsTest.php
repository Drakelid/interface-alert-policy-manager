<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\User;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
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

    public function test_simulate_requires_the_manage_ability(): void
    {
        $port = $this->downPort($this->device());

        $this->actingAs(User::factory()->create())
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

        $this->actingAs($admin)
            ->post('/plugin/interface-alert-policy-manager/import', ['document' => $json])
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
        $document = json_encode(['version' => 1, 'schedules' => [], 'policies' => [['name' => 'Keep me', 'severity' => 'critical', 'enabled' => true]]]);

        $this->actingAs($admin)
            ->post('/plugin/interface-alert-policy-manager/import', ['document' => $document])
            ->assertOk()
            ->assertSee('already exists');

        self::assertSame(1, Policy::where('name', 'Keep me')->count());
    }
}
