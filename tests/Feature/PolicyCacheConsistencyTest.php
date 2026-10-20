<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyCacheRebuilder;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class PolicyCacheConsistencyTest extends IntegrationTestCase
{
    public function test_deleting_an_assignment_marks_a_completed_cache_stale_even_in_the_same_second(): void
    {
        $this->downPort($this->device());
        $policy = $this->defaultPolicy();
        $this->rebuild();
        self::assertFalse(app(PolicyCacheRebuilder::class)->isStale());

        $policy->assignments()->sole()->delete();

        self::assertTrue(app(PolicyCacheRebuilder::class)->isStale());
        self::assertSame(0, DB::table('iapm_interface_policy_cache')->count());
    }

    public function test_deleting_the_last_policy_marks_the_cache_stale(): void
    {
        $this->downPort($this->device());
        $policy = $this->defaultPolicy();
        $this->rebuild();

        $policy->delete();

        self::assertTrue(app(PolicyCacheRebuilder::class)->isStale());
    }

    public function test_bulk_removing_port_assignments_marks_the_cache_stale(): void
    {
        $port = $this->downPort($this->device());
        $fallback = $this->defaultPolicy();
        $specific = $this->policy();
        $specific->assignments()->create(['assignment_type' => 'port', 'assignment_reference' => $port->port_id, 'match_mode' => 'any']);
        $this->rebuild();

        $this->actingAs($this->admin())->post('/plugin/interface-alert-policy-manager/interface-matrix/bulk', [
            'operation' => 'remove_assignment', 'port_ids' => [$port->port_id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        self::assertTrue(app(PolicyCacheRebuilder::class)->isStale());
        self::assertSame(0, $specific->assignments()->count());
        $this->rebuild();
        self::assertDatabaseHas('iapm_interface_policy_cache', ['port_id' => $port->port_id, 'policy_id' => $fallback->id]);
    }

    public function test_muting_does_not_discard_unchanged_policy_cache_rows(): void
    {
        $port = $this->downPort($this->device());
        $policy = $this->defaultPolicy();
        $this->incident($policy, $port);
        $this->rebuild();

        $this->actingAs($this->admin())->post('/plugin/interface-alert-policy-manager/interface-matrix/bulk', [
            'operation' => 'mute', 'port_ids' => [$port->port_id], 'muted_until' => now()->addHour()->toIso8601String(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        self::assertDatabaseHas('iapm_interface_policy_cache', ['port_id' => $port->port_id, 'policy_id' => $policy->id]);
        self::assertFalse(app(PolicyCacheRebuilder::class)->isStale());
    }

    public function test_the_cache_clear_command_marks_the_empty_cache_stale(): void
    {
        $this->downPort($this->device());
        $this->defaultPolicy();
        $this->rebuild();

        $this->artisan('iapm:cache-clear')->assertSuccessful();

        self::assertSame(0, DB::table('iapm_interface_policy_cache')->count());
        self::assertTrue(app(PolicyCacheRebuilder::class)->isStale());
    }

    public function test_changing_the_configured_default_invalidates_cache_and_rebuilds_the_new_policy(): void
    {
        $port = $this->downPort($this->device());
        $first = $this->policy();
        $second = $this->policy();
        $this->settings->put('default_policy_id', $first->id);
        $this->rebuild();
        self::assertDatabaseHas('iapm_interface_policy_cache', ['port_id' => $port->port_id, 'policy_id' => $first->id]);

        $this->actingAs($this->admin())->put('/plugin/interface-alert-policy-manager/settings', [
            'default_policy_id' => $second->id, 'dry_run' => '0', 'record_unpoliced' => '1',
            'retention_days' => 365, 'notification_timeout' => 15, 'notification_retry_count' => 2,
            'deleted_port_behavior' => 'recover', 'aggregate_threshold' => 0,
            'aggregate_window_seconds' => 120, 'dispatch_mode' => 'sync',
        ])->assertRedirect()->assertSessionHasNoErrors();

        self::assertTrue(app(PolicyCacheRebuilder::class)->isStale());
        self::assertSame(0, DB::table('iapm_interface_policy_cache')->count());
        $this->rebuild();
        self::assertFalse(app(PolicyCacheRebuilder::class)->isStale());
        self::assertDatabaseHas('iapm_interface_policy_cache', ['port_id' => $port->port_id, 'policy_id' => $second->id]);
    }

    public function test_an_edit_between_batches_cannot_mark_an_incomplete_cache_complete(): void
    {
        $device = $this->device();
        $this->downPort($device);
        $this->downPort($device);
        $policy = $this->defaultPolicy();
        // A worker has its own setting cache; a web request's edit must be
        // observed immediately, including inside the normal five-second TTL.
        $rebuilder = new PolicyCacheRebuilder(new SettingStore, app(InterfaceContextService::class), app(PolicyResolver::class));
        $rebuilder->markQueued();
        $firstBatch = $rebuilder->runBatch(null, 1);
        self::assertFalse($firstBatch['done']);
        self::assertSame(1, DB::table('iapm_interface_policy_cache')->count());

        $policy->update(['priority' => 42]);
        $lastBatch = $rebuilder->runBatch($firstBatch['last'], 10);

        self::assertTrue($lastBatch['done'], 'The interrupted job must stop redispatching cursor batches.');
        self::assertSame('failed', $rebuilder->state()['status']);
        self::assertTrue($rebuilder->isStale());
        self::assertStringContainsString('configuration changed', $rebuilder->state()['error']);
        self::assertNull($rebuilder->rebuiltAt());

        $this->rebuild();
        self::assertSame(2, DB::table('iapm_interface_policy_cache')->count());
        self::assertFalse(app(PolicyCacheRebuilder::class)->isStale());
    }

    public function test_a_command_reports_failure_when_configuration_changes_during_its_scan(): void
    {
        $this->downPort($this->device());
        $policy = $this->defaultPolicy();
        $changed = false;
        DB::listen(function ($query) use ($policy, &$changed): void {
            if (! $changed && str_starts_with(strtolower(ltrim($query->sql)), 'insert') && str_contains($query->sql, 'iapm_interface_policy_cache')) {
                $changed = true;
                $policy->update(['priority' => 42]);
            }
        });

        $this->artisan('iapm:cache-rebuild')
            ->expectsOutputToContain('configuration changed during the rebuild')
            ->assertFailed();

        self::assertTrue($changed, 'The configuration edit must occur inside the rebuild scan.');
        self::assertSame('failed', app(PolicyCacheRebuilder::class)->state()['status']);
        self::assertTrue(app(PolicyCacheRebuilder::class)->isStale());
        self::assertNull(app(PolicyCacheRebuilder::class)->rebuiltAt());
    }

    private function rebuild(): void
    {
        $rebuilder = app(PolicyCacheRebuilder::class);
        $rebuilder->markQueued();
        self::assertTrue($rebuilder->runBatch(null, 100)['done']);
        self::assertSame('complete', $rebuilder->state()['status']);
    }
}
