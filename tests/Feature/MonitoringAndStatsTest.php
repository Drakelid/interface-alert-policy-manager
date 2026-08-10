<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\NotificationOutbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Outage;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\HealthService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class MonitoringAndStatsTest extends IntegrationTestCase
{
    public function test_health_is_unhealthy_when_the_scheduler_has_not_run(): void
    {
        $this->artisan('iapm:health')
            ->expectsOutputToContain('[FAIL] Reconciliation running')
            ->assertExitCode(1);
    }

    public function test_the_incident_screen_can_reconcile_and_resend_over_http(): void
    {
        // Both actions shell out through Artisan::call() from a web request.
        // Registering the console commands behind runningInConsole() made them
        // fail with CommandNotFoundException in the browser. PHPUnit itself runs
        // in console, so this guards the routing, authorization and wiring; the
        // console/web distinction was verified against a live install.
        //
        // A default assignment keeps the policy attached: reconcile runs first and
        // would otherwise correctly detach an unassigned policy as "no_policy",
        // making the resend a 422 for an unrelated reason.
        $policy = $this->defaultPolicy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));

        $this->actingAs($this->admin())
            ->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/reconcile")
            ->assertRedirect();

        $this->actingAs($this->admin())
            ->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/resend", ['action_id' => $action->id])
            ->assertRedirect();

        self::assertArrayHasKey('iapm:reconcile', Artisan::all());
        self::assertArrayHasKey('iapm:process-actions', Artisan::all());
    }

    public function test_health_passes_once_the_scheduled_commands_have_run(): void
    {
        $this->settings->put('last_reconcile_at', now()->toIso8601String());
        $this->settings->put('last_process_actions_at', now()->toIso8601String());

        self::assertTrue(app(HealthService::class)->healthy());
        $this->artisan('iapm:health')->assertExitCode(0);
    }

    public function test_reconcile_records_its_run_timestamp(): void
    {
        $this->artisan('iapm:reconcile')->assertExitCode(0);

        self::assertNotNull($this->settings->get('last_reconcile_at'));
    }

    public function test_recovering_an_incident_writes_an_outage_record(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();
        $this->ingest($this->alertPayload($device, [], 0, ['timestamp' => now()->addMinute()->toIso8601String()]))->assertOk();

        self::assertSame(1, Outage::count());
        $outage = Outage::first();
        self::assertSame((int) $port->port_id, (int) $outage->port_id);
        self::assertNotNull($outage->recovered_at);
        self::assertNotNull($outage->duration_seconds);
    }

    public function test_the_stats_page_renders_sla_metrics(): void
    {
        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager/stats')
            ->assertOk()
            ->assertSee('MTTR')
            ->assertSee('Noisiest interfaces');
    }

    public function test_the_comparison_csv_export_downloads(): void
    {
        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager/comparison-report/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_two_episodes_on_one_incident_are_counted_twice(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);
        $this->ingest($this->alertPayload($device, [$this->fault($port)]));
        $this->ingest($this->alertPayload($device, [], 0, ['timestamp' => now()->addMinute()->toIso8601String()]));
        $this->ingest($this->alertPayload($device, [$this->fault($port)], 1, ['timestamp' => now()->addMinutes(2)->toIso8601String()]));
        $this->ingest($this->alertPayload($device, [], 0, ['timestamp' => now()->addMinutes(3)->toIso8601String()]));

        self::assertSame(1, Incident::count());
        self::assertSame(2, Outage::count());
        $this->actingAs($this->admin())->get('/plugin/interface-alert-policy-manager/stats')->assertOk()->assertSee('>2<', false);
    }

    public function test_previous_episode_delivery_does_not_make_current_episode_healthy(): void
    {
        $this->settings->put('last_reconcile_at', now()->toIso8601String());
        $this->settings->put('last_process_actions_at', now()->toIso8601String());
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()), ['first_seen_at' => now()->subMinutes(20), 'triggered_at' => now()->subMinutes(20)]);
        DeliveryLog::create(['incident_id' => $incident->id, 'episode_uuid' => '00000000-0000-0000-0000-000000000000', 'destination_id' => $action->destination_id, 'policy_action_id' => $action->id, 'phase' => 'trigger', 'status' => 'sent']);
        self::assertFalse(app(HealthService::class)->healthy());

        DeliveryLog::create(['incident_id' => $incident->id, 'episode_uuid' => $incident->episode_uuid, 'destination_id' => $action->destination_id, 'policy_action_id' => $action->id, 'phase' => 'trigger', 'status' => 'sent']);
        self::assertTrue(app(HealthService::class)->healthy());
    }

    public function test_stale_in_flight_outbox_is_unhealthy(): void
    {
        $this->settings->put('last_reconcile_at', now()->toIso8601String());
        $this->settings->put('last_process_actions_at', now()->toIso8601String());
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));
        NotificationOutbox::create(['idempotency_key' => hash('sha256', 'stale'), 'episode_uuid' => $incident->episode_uuid, 'incident_id' => $incident->id, 'destination_id' => $action->destination_id, 'policy_action_id' => $action->id, 'phase' => 'trigger', 'receiver_hash' => hash('sha256', 'noc'), 'receiver_encrypted' => 'noc', 'message_encrypted' => 'down', 'incident_ids_encrypted' => [$incident->id], 'status' => 'queued', 'created_at' => now()->subHour()]);
        self::assertFalse(app(HealthService::class)->healthy());
    }

    public function test_failed_outbox_that_has_remained_due_is_unhealthy(): void
    {
        $this->settings->put('last_reconcile_at', now()->toIso8601String());
        $this->settings->put('last_process_actions_at', now()->toIso8601String());
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));
        NotificationOutbox::create(['idempotency_key' => hash('sha256', 'failed-due'), 'episode_uuid' => $incident->episode_uuid, 'incident_id' => $incident->id, 'destination_id' => $action->destination_id, 'policy_action_id' => $action->id, 'phase' => 'trigger', 'receiver_hash' => hash('sha256', 'noc'), 'receiver_encrypted' => 'noc', 'message_encrypted' => 'down', 'incident_ids_encrypted' => [], 'status' => 'failed', 'available_at' => now()->subHour(), 'created_at' => now()->subHour()]);

        self::assertFalse(app(HealthService::class)->healthy());
    }

    public function test_backlog_query_failure_fails_health_closed(): void
    {
        Schema::rename('iapm_notification_outbox', 'iapm_notification_outbox_unavailable');
        try {
            $check = collect(app(HealthService::class)->checks())->firstWhere('key', 'action_backlog');
            self::assertFalse($check['ok']);
            self::assertStringContainsString('query failed', strtolower($check['detail']));
        } finally {
            Schema::rename('iapm_notification_outbox_unavailable', 'iapm_notification_outbox');
            if (! DB::connection()->getPdo()->inTransaction()) {
                $connection = DB::connection();
                $connection->setPdo($connection->getPdo());
                $connection->beginTransaction();
            }
        }
    }
}
