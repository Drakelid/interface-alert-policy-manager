<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Command;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\IncidentLifecycleService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class ReconcileConcurrencyTest extends IntegrationTestCase
{
    public function test_reconcile_does_not_resurrect_a_recovery_after_loading_its_batch(): void
    {
        $policy = $this->defaultPolicy();
        $incident = $this->incident($policy, $this->downPort($this->device()), [
            'state' => IncidentState::Pending,
            'triggered_at' => null,
            'first_seen_at' => now()->subMinutes(10),
        ]);
        $interleaved = false;
        Incident::retrieved(function () use ($incident, &$interleaved): void {
            if (! $interleaved) {
                $interleaved = true;
                app(IncidentLifecycleService::class)->recover($incident, 'Concurrent webhook recovery.');
            }
        });
        try {
            $this->artisan('iapm:reconcile')->assertExitCode(0);
        } finally {
            Incident::getEventDispatcher()->forget('eloquent.retrieved: '.Incident::class);
        }

        self::assertTrue($interleaved);
        self::assertSame(IncidentState::Recovered, $incident->fresh()->state);
        self::assertSame(0, $incident->events()->where('event_type', 'reconciled')->count());
    }

    public function test_conditional_activation_refreshes_a_stale_pending_snapshot_without_resurrection(): void
    {
        $policy = $this->defaultPolicy();
        $incident = $this->incident($policy, $this->downPort($this->device()), [
            'state' => IncidentState::Pending,
            'triggered_at' => null,
            'first_seen_at' => now()->subMinutes(10),
        ]);
        $snapshot = $incident->fresh();
        $lifecycle = app(IncidentLifecycleService::class);
        $lifecycle->recover($incident, 'Concurrent webhook recovery.');

        self::assertFalse($lifecycle->activateIfEligible($snapshot));
        self::assertSame(IncidentState::Recovered, $snapshot->state);
        self::assertSame(0, $incident->events()->where('event_type', 'activated')->count());
    }

    public function test_conditional_activation_keeps_a_concurrent_acknowledgement(): void
    {
        $policy = $this->defaultPolicy();
        $incident = $this->incident($policy, $this->downPort($this->device()), [
            'state' => IncidentState::Pending,
            'triggered_at' => null,
            'first_seen_at' => now()->subMinutes(10),
        ]);
        $snapshot = $incident->fresh();
        $lifecycle = app(IncidentLifecycleService::class);
        $lifecycle->acknowledge($incident);

        self::assertFalse($lifecycle->activateIfEligible($snapshot));
        self::assertSame(IncidentState::Acknowledged, $snapshot->state);
    }

    public function test_conditional_activation_rejects_a_replaced_outage_episode(): void
    {
        $policy = $this->defaultPolicy();
        $incident = $this->incident($policy, $this->downPort($this->device()), [
            'state' => IncidentState::Pending,
            'triggered_at' => null,
        ]);
        $snapshot = $incident->fresh();
        $lifecycle = app(IncidentLifecycleService::class);
        $lifecycle->recover($incident, 'First outage recovered.');
        $incident->update(array_replace($lifecycle->beginEpisode($incident, (array) $incident->context_json), [
            'state' => IncidentState::Pending,
            'first_seen_at' => now()->subMinutes(10),
        ]));

        self::assertFalse($lifecycle->activateIfEligible($snapshot));
        self::assertSame(IncidentState::Pending, $snapshot->state);
        self::assertSame($incident->episode_uuid, $snapshot->episode_uuid);
    }

    public function test_stale_recovery_snapshots_cannot_close_a_new_outage_episode(): void
    {
        $incident = $this->incident($this->defaultPolicy(), $this->downPort($this->device()));
        $snapshot = $incident->fresh();
        $lifecycle = app(IncidentLifecycleService::class);
        $lifecycle->recover($incident, 'First outage recovered.');
        $incident->update(array_replace($lifecycle->beginEpisode($incident, (array) $incident->context_json), ['state' => IncidentState::Active]));

        self::assertFalse($lifecycle->recover($snapshot, 'Delayed recovery.'));
        self::assertSame(0, $lifecycle->recoverMany([$snapshot], 'Delayed batch recovery.'));
        self::assertSame(IncidentState::Active, $incident->fresh()->state);
        self::assertSame(1, $incident->events()->where('event_type', 'recovered')->count());
    }

    public function test_batch_recovery_rechecks_the_source_after_acquiring_the_lock(): void
    {
        $incident = $this->incident($this->defaultPolicy(), $this->downPort($this->device()));
        $snapshot = $incident->fresh();
        $incident->update(['source_alert_uid' => 'a-new-source-alert']);

        self::assertSame(0, app(IncidentLifecycleService::class)->recoverMany([$snapshot], 'Delayed recovery from old source.'));
        self::assertSame(IncidentState::Active, $incident->fresh()->state);
    }
}
