<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\RebuildPolicyCacheJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyCacheRebuilder;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;
use Spatie\Permission\Models\Permission;

/**
 * P1-7: the Interface Matrix showed an info banner telling the operator to run
 * `php artisan iapm:cache-rebuild`, which a web-only administrator cannot do.
 */
class PolicyCacheRebuildTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    public function test_the_matrix_offers_a_rebuild_button_instead_of_a_command(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/interface-matrix')->assertOk()->getContent();

        self::assertStringContainsString(route('iapm.matrix.rebuild-cache'), $body);
        self::assertStringContainsString('Rebuild cache', $body);
        self::assertStringNotContainsString('iapm:cache-rebuild', $body);
    }

    /**
     * The rule behind P1-7, applied to every page: a web-only administrator
     * must never be handed a shell command as the way forward.
     */
    public function test_no_page_tells_the_operator_to_run_a_shell_command(): void
    {
        $admin = $this->admin();

        foreach ($this->paths() as $path) {
            $body = (string) $this->actingAs($admin)->get($path)->assertOk()->getContent();
            foreach (['php artisan', './lnms', 'sudo -u librenms'] as $command) {
                self::assertStringNotContainsString($command, $body, "$path instructs the operator to run \"$command\".");
            }
        }
    }

    /**
     * The same is true when the health panel is unhappy, which is exactly when
     * the old copy surfaced its `php artisan queue:work` advice.
     */
    public function test_an_unhealthy_overview_still_avoids_shell_commands(): void
    {
        // No scheduler timestamps recorded => every scheduler check reports down.
        $body = (string) $this->actingAs($this->admin())->get(self::BASE)->assertOk()->getContent();

        self::assertStringContainsString('health needs attention', $body, 'Expected the fixture to produce an unhealthy panel.');
        self::assertStringNotContainsString('php artisan', $body);
    }

    public function test_the_button_queues_a_rebuild_job(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())
            ->post(self::BASE.'/interface-matrix/rebuild-cache')
            ->assertRedirect();

        Queue::assertPushed(RebuildPolicyCacheJob::class);
        self::assertTrue(app(PolicyCacheRebuilder::class)->state()['running']);
    }

    public function test_a_rebuild_populates_the_cache_and_records_when_it_finished(): void
    {
        $this->defaultPolicy();
        $port = $this->downPort($this->device());
        DB::table('iapm_interface_policy_cache')->delete();

        $this->actingAs($this->admin())->post(self::BASE.'/interface-matrix/rebuild-cache')->assertRedirect();

        // QUEUE_CONNECTION is sync in tests, so the job has already run.
        $state = app(PolicyCacheRebuilder::class)->state();
        self::assertSame('complete', $state['status']);
        self::assertNotNull($state['rebuilt_at']);
        self::assertFalse($state['running']);
        self::assertDatabaseHas('iapm_interface_policy_cache', ['port_id' => $port->port_id]);
    }

    public function test_progress_can_be_polled_while_a_rebuild_runs(): void
    {
        Queue::fake();
        $this->downPort($this->device());

        $this->actingAs($this->admin())->post(self::BASE.'/interface-matrix/rebuild-cache');

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/interface-matrix/cache-status')
            ->assertOk()
            ->assertJsonStructure(['status', 'progress', 'total', 'rebuilt_at', 'stale', 'running']);
    }

    public function test_a_stalled_rebuild_is_immediately_visible_and_can_be_retried(): void
    {
        Queue::fake();
        $rebuilder = app(PolicyCacheRebuilder::class);
        $rebuilder->markQueued();
        $this->travel(6)->minutes();

        $state = $rebuilder->state();
        self::assertSame('stalled', $state['status']);
        self::assertFalse($state['running']);

        $this->actingAs($this->admin())
            ->get(self::BASE.'/interface-matrix')
            ->assertOk()
            ->assertSeeText('The rebuild stopped making progress. Retry it');

        $this->actingAs($this->admin())
            ->post(self::BASE.'/interface-matrix/rebuild-cache')
            ->assertRedirect();

        Queue::assertPushed(RebuildPolicyCacheJob::class);
        self::assertSame('queued', $rebuilder->state()['status']);
    }

    public function test_the_matrix_reloads_itself_after_detecting_a_completed_rebuild(): void
    {
        $body = (string) $this->actingAs($this->admin())
            ->get(self::BASE.'/interface-matrix')
            ->assertOk()
            ->getContent();

        self::assertStringContainsString('observedRebuiltAt', $body);
        self::assertStringContainsString('data.rebuilt_at !== observedRebuiltAt', $body);
        self::assertStringContainsString('window.location.reload()', $body);
        self::assertStringContainsString('idlePollMilliseconds = 30000', $body);
    }

    /** The staleness warning is the reason the button exists. */
    public function test_the_matrix_warns_when_policies_changed_after_the_last_rebuild(): void
    {
        $rebuilder = app(PolicyCacheRebuilder::class);
        $this->downPort($this->device());

        $policy = $this->defaultPolicy();
        $rebuilder->markCompletedNow();
        self::assertFalse($rebuilder->isStale(), 'A freshly rebuilt cache should not be stale.');

        $this->travel(2)->minutes();
        $policy->touch();

        self::assertTrue($rebuilder->isStale());
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/interface-matrix')->assertOk()->getContent();
        self::assertStringContainsString('may be out of date', $body);
    }

    /** Rebuilding is deliberately manual, so a save must not silently trigger one. */
    public function test_saving_a_policy_does_not_queue_a_rebuild(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())->post(self::BASE.'/policies', [
            'name' => 'No auto rebuild', 'severity' => 'critical', 'priority' => 0,
            'trigger_after_seconds' => 0, 'down_observations' => 1, 'recovery_after_seconds' => 0,
            'enabled' => '1', 'notifications_enabled' => '1',
        ])->assertRedirect();

        Queue::assertNotPushed(RebuildPolicyCacheJob::class);
    }

    public function test_rebuilding_requires_permission_to_manage_assignments(): void
    {
        Permission::findOrCreate('view iapm', 'web');
        $viewer = User::factory()->create(['enabled' => true]);
        $viewer->givePermissionTo('view iapm');

        $this->actingAs($viewer)->post(self::BASE.'/interface-matrix/rebuild-cache')->assertForbidden();
    }

    /** A per-device CLI rebuild must not clear a fleet-wide staleness warning. */
    public function test_a_single_device_command_rebuild_does_not_clear_the_warning(): void
    {
        $device = $this->device();
        $this->downPort($device);
        $this->defaultPolicy();

        $this->artisan('iapm:cache-rebuild', ['--device' => $device->device_id])->assertSuccessful();

        self::assertNull(app(PolicyCacheRebuilder::class)->rebuiltAt());
    }

    public function test_a_full_command_rebuild_records_the_timestamp(): void
    {
        $this->downPort($this->device());
        $this->defaultPolicy();

        $this->artisan('iapm:cache-rebuild')->assertSuccessful();

        self::assertNotNull(app(PolicyCacheRebuilder::class)->rebuiltAt());
    }

    public function test_the_queue_option_starts_a_worker_safe_rebuild(): void
    {
        Queue::fake();
        $this->downPort($this->device());

        $this->artisan('iapm:cache-rebuild', ['--queue' => true])
            ->expectsOutput('Policy cache rebuild queued.')
            ->assertSuccessful();

        Queue::assertPushed(RebuildPolicyCacheJob::class);
        $state = app(PolicyCacheRebuilder::class)->state();
        self::assertSame('queued', $state['status']);
        self::assertSame(0, $state['progress']);
        self::assertTrue($state['running']);
    }

    public function test_a_full_cache_rebuild_is_scheduled_every_hour(): void
    {
        $event = collect(app(ConsoleSchedule::class)->events())
            ->first(fn ($event) => str_contains((string) ($event->command ?? ''), 'iapm:cache-rebuild'));

        self::assertNotNull($event, 'The scheduler did not register iapm:cache-rebuild.');
        self::assertSame('0 * * * *', $event->expression);
        self::assertStringContainsString('--queue', (string) $event->command);
        self::assertFalse($event->runInBackground);
    }

    /** @return list<string> */
    private function paths(): array
    {
        $policy = $this->defaultPolicy();
        $destination = $this->smsDestination();
        $action = $this->triggerAction($policy, $destination);
        $incident = $this->incident($policy, $this->downPort($this->device()));
        $base = self::BASE;

        return [
            $base,
            "$base/policies", "$base/policies/create", "$base/policies/{$policy->id}/edit",
            "$base/policies/{$policy->id}/actions/create", "$base/actions/{$action->id}/edit",
            "$base/assignments", "$base/assignments/create",
            "$base/interface-matrix", "$base/policy-test", "$base/stats",
            "$base/tools/simulate", "$base/import", "$base/comparison-report",
            "$base/setup-helper", "$base/template-preview", "$base/message-templates",
            "$base/destinations", "$base/destinations/create", "$base/destinations/{$destination->id}/edit",
            "$base/incidents", "$base/incidents/{$incident->id}",
            "$base/settings", "$base/delivery-log", "$base/audit-log",
        ];
    }
}
