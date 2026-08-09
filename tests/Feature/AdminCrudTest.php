<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Schedule;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class AdminCrudTest extends IntegrationTestCase
{
    public function test_policy_and_action_write_paths_round_trip_and_clone(): void
    {
        $admin = $this->admin();
        $destination = $this->smsDestination();

        $this->actingAs($admin)->post('/plugin/interface-alert-policy-manager/policies', $this->policyPayload('CRUD policy'))
            ->assertRedirect();
        $policy = Policy::where('name', 'CRUD policy')->firstOrFail();
        self::assertSame('warning', $policy->severity->value);

        $this->actingAs($admin)->put("/plugin/interface-alert-policy-manager/policies/{$policy->id}", $this->policyPayload('CRUD policy updated', 12))
            ->assertRedirect();
        self::assertSame(12, $policy->fresh()->priority);

        $this->actingAs($admin)->post("/plugin/interface-alert-policy-manager/policies/{$policy->id}/actions", [
            'destination_id' => $destination->id,
            'phase' => 'trigger',
            'delay_seconds' => 0,
            'receivers_text' => "noc-a\nnoc-b",
            'enabled' => '1',
            'sort_order' => 0,
        ])->assertRedirect();
        $action = PolicyAction::where('policy_id', $policy->id)->sole();
        self::assertSame(['noc-a', 'noc-b'], $action->receivers_json);

        $this->actingAs($admin)->put("/plugin/interface-alert-policy-manager/actions/{$action->id}", [
            'destination_id' => $destination->id,
            'phase' => 'escalation',
            'delay_seconds' => 300,
            'repeat_seconds' => 600,
            'maximum_sends' => 2,
            'receivers_text' => 'manager',
            'enabled' => '1',
            'sort_order' => 5,
        ])->assertRedirect();
        self::assertSame('escalation', $action->fresh()->phase->value);
        self::assertSame(['manager'], $action->fresh()->receivers_json);

        $this->actingAs($admin)->post("/plugin/interface-alert-policy-manager/policies/{$policy->id}/clone")->assertRedirect();
        $copy = Policy::where('name', 'CRUD policy updated (copy)')->firstOrFail();
        self::assertFalse($copy->enabled);
        self::assertSame(1, $copy->actions()->count());

        $this->actingAs($admin)->delete("/plugin/interface-alert-policy-manager/actions/{$action->id}")->assertRedirect();
        self::assertFalse(PolicyAction::whereKey($action->id)->exists());
    }

    public function test_assignment_write_paths_preserve_receivers_and_invalidate_cache(): void
    {
        $admin = $this->admin();
        $policy = $this->policy();

        $this->actingAs($admin)->post('/plugin/interface-alert-policy-manager/assignments', [
            'policy_id' => $policy->id,
            'assignment_type' => 'default',
            'match_mode' => 'any',
            'priority' => 5,
            'enabled' => '1',
            'receivers_text' => "noc-a,noc-b\nnoc-a",
        ])->assertRedirect();
        $assignment = Assignment::sole();
        self::assertSame(['noc-a', 'noc-b', 'noc-a'], $assignment->metadata_json['receivers']);

        $this->actingAs($admin)->put("/plugin/interface-alert-policy-manager/assignments/{$assignment->id}", [
            'policy_id' => $policy->id,
            'assignment_type' => 'ifname_regex',
            'match_expression' => '/^xe-/',
            'match_mode' => 'any',
            'priority' => 9,
            'enabled' => '1',
            'receivers_text' => 'regex-noc',
        ])->assertRedirect();
        self::assertSame('ifname_regex', $assignment->fresh()->assignment_type->value);
        self::assertSame(['regex-noc'], $assignment->fresh()->metadata_json['receivers']);

        $this->actingAs($admin)->delete("/plugin/interface-alert-policy-manager/assignments/{$assignment->id}")->assertRedirect();
        self::assertFalse(Assignment::whereKey($assignment->id)->exists());
    }

    public function test_schedule_destination_clone_settings_and_token_write_paths(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/plugin/interface-alert-policy-manager/schedules', [
            'name' => 'Business hours',
            'timezone' => 'Europe/Oslo',
            'enabled' => '1',
            'schedule_json' => json_encode(['mode' => 'always', 'days' => []]),
        ])->assertRedirect();
        $schedule = Schedule::where('name', 'Business hours')->firstOrFail();
        $this->actingAs($admin)->put("/plugin/interface-alert-policy-manager/schedules/{$schedule->id}", [
            'name' => 'Business hours updated',
            'timezone' => 'UTC',
            'enabled' => '1',
            'schedule_json' => json_encode(['mode' => 'always', 'days' => []]),
        ])->assertRedirect();
        self::assertSame('UTC', $schedule->fresh()->timezone);

        $destination = $this->smsDestination(['password' => 'clone-secret']);
        $this->actingAs($admin)->post("/plugin/interface-alert-policy-manager/destinations/{$destination->id}/clone")->assertRedirect();
        $copy = Destination::where('name', $destination->name.' (copy)')->firstOrFail();
        self::assertFalse($copy->enabled);
        self::assertSame('clone-secret', $copy->configuration_encrypted['password']);

        $policy = $this->policy();
        $this->actingAs($admin)->put('/plugin/interface-alert-policy-manager/settings', [
            'dry_run' => '1',
            'record_unpoliced' => '1',
            'default_policy_id' => $policy->id,
            'sms_default_receiver' => 'global-noc',
            'retention_days' => 730,
            'notification_timeout' => 30,
            'notification_retry_count' => 3,
            'deleted_port_behavior' => 'retain',
            'url_base' => 'https://librenms.example.test',
            'aggregate_threshold' => 10,
            'aggregate_window_seconds' => 180,
            'dispatch_mode' => 'queue',
        ])->assertRedirect();
        self::assertSame('queue', $this->settings->get('dispatch_mode'));
        self::assertSame('global-noc', $this->settings->get('sms_default_receiver'));

        $oldToken = $this->settings->get('ingestion_token');
        $response = $this->actingAs($admin)->post('/plugin/interface-alert-policy-manager/settings/rotate-token')->assertRedirect();
        self::assertSame($oldToken, $this->settings->get('previous_ingestion_token'));
        self::assertNotSame($oldToken, $this->settings->get('ingestion_token'));
        self::assertNotEmpty($response->getSession()->get('new_ingestion_token'));

        $this->actingAs($admin)->delete("/plugin/interface-alert-policy-manager/schedules/{$schedule->id}")->assertRedirect();
        self::assertFalse(Schedule::whereKey($schedule->id)->exists());
    }

    private function policyPayload(string $name, int $priority = 1): array
    {
        return [
            'name' => $name,
            'description' => 'CRUD coverage',
            'enabled' => '1',
            'priority' => $priority,
            'severity' => 'warning',
            'default_receiver' => 'noc',
            'notifications_enabled' => '1',
            'trigger_after_seconds' => 0,
            'failed_poll_count' => 1,
            'recovery_after_seconds' => 0,
            'repeat_seconds' => 300,
            'maximum_repeats' => 3,
            'notify_recovery' => '1',
            'suppress_device_down' => '1',
            'suppress_admin_down' => '1',
            'suppress_ignored_port' => '1',
            'suppress_disabled_port' => '1',
            'suppress_deleted_port' => '1',
            'suppress_maintenance' => '1',
            'suppress_parent_down' => '1',
        ];
    }
}
