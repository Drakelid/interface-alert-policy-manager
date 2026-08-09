<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class RouteSmokeTest extends IntegrationTestCase
{
    public function test_every_user_facing_page_renders_for_an_administrator(): void
    {
        $admin = $this->admin();
        $policy = $this->defaultPolicy();
        $assignment = $policy->assignments()->firstOrFail();
        $destination = $this->smsDestination();
        $action = $this->triggerAction($policy, $destination);
        $schedule = Schedule::create([
            'name' => 'Always',
            'timezone' => 'UTC',
            'enabled' => true,
            'schedule_json' => ['mode' => 'always', 'periods' => []],
        ]);
        $incident = $this->incident($policy, $this->downPort($this->device()));

        $paths = [
            '/plugin/interface-alert-policy-manager',
            '/plugin/interface-alert-policy-manager/policies',
            '/plugin/interface-alert-policy-manager/policies/create',
            "/plugin/interface-alert-policy-manager/policies/{$policy->id}/edit",
            "/plugin/interface-alert-policy-manager/policies/{$policy->id}/actions/create",
            "/plugin/interface-alert-policy-manager/actions/{$action->id}/edit",
            '/plugin/interface-alert-policy-manager/assignments',
            '/plugin/interface-alert-policy-manager/assignments/create',
            "/plugin/interface-alert-policy-manager/assignments/{$assignment->id}/edit",
            '/plugin/interface-alert-policy-manager/interface-matrix',
            '/plugin/interface-alert-policy-manager/policy-test',
            '/plugin/interface-alert-policy-manager/stats',
            '/plugin/interface-alert-policy-manager/tools/simulate',
            '/plugin/interface-alert-policy-manager/import',
            '/plugin/interface-alert-policy-manager/comparison-report',
            '/plugin/interface-alert-policy-manager/setup-helper',
            '/plugin/interface-alert-policy-manager/template-preview',
            '/plugin/interface-alert-policy-manager/message-templates',
            '/plugin/interface-alert-policy-manager/schedules',
            '/plugin/interface-alert-policy-manager/schedules/create',
            "/plugin/interface-alert-policy-manager/schedules/{$schedule->id}/edit",
            '/plugin/interface-alert-policy-manager/destinations',
            '/plugin/interface-alert-policy-manager/destinations/create',
            "/plugin/interface-alert-policy-manager/destinations/{$destination->id}/edit",
            '/plugin/interface-alert-policy-manager/incidents',
            "/plugin/interface-alert-policy-manager/incidents/{$incident->id}",
            '/plugin/interface-alert-policy-manager/settings',
            '/plugin/interface-alert-policy-manager/delivery-log',
            '/plugin/interface-alert-policy-manager/audit-log',
        ];

        foreach ($paths as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
    }
}
