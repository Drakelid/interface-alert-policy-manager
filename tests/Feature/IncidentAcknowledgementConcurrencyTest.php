<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Str;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\IncidentEvent;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\IncidentLifecycleService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class IncidentAcknowledgementConcurrencyTest extends IntegrationTestCase
{
    public static function transitions(): array
    {
        return [['acknowledge'], ['unacknowledge']];
    }

    #[DataProvider('transitions')]
    public function test_an_old_snapshot_cannot_resurrect_a_recovered_incident(string $transition): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()), [
            'state' => $transition === 'acknowledge' ? IncidentState::Active : IncidentState::Acknowledged,
            'pre_acknowledgement_state' => IncidentState::Active->value,
        ]);
        $snapshot = $incident->fresh();
        $lifecycle = app(IncidentLifecycleService::class);
        $lifecycle->recover($incident, 'Concurrent recovery');

        $rejected = false;
        try {
            $lifecycle->{$transition}($snapshot);
        } catch (\DomainException) {
            $rejected = true;
        }

        self::assertTrue($rejected);
        self::assertSame(IncidentState::Recovered, $incident->fresh()->state);
        self::assertNotNull($incident->fresh()->recovered_at);
        self::assertSame(0, $incident->events()->whereIn('event_type', ['acknowledged', 'unacknowledged'])->count());
    }

    #[DataProvider('transitions')]
    public function test_an_old_snapshot_cannot_change_a_new_pending_episode(string $transition): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()), [
            'state' => $transition === 'acknowledge' ? IncidentState::Active : IncidentState::Acknowledged,
            'pre_acknowledgement_state' => IncidentState::Active->value,
        ]);
        $snapshot = $incident->fresh();
        $lifecycle = app(IncidentLifecycleService::class);
        $lifecycle->recover($incident, 'Concurrent recovery');
        $incident->update(array_merge($lifecycle->beginEpisode($incident, ['observation_count' => 1]), [
            'state' => IncidentState::Pending,
            'source_alert_uid' => 'new-episode',
        ]));
        $newEpisode = $incident->episode_uuid;

        $rejected = false;
        try {
            $lifecycle->{$transition}($snapshot);
        } catch (\DomainException) {
            $rejected = true;
        }

        self::assertTrue($rejected);
        self::assertSame($newEpisode, $incident->fresh()->episode_uuid);
        self::assertSame(IncidentState::Pending, $incident->fresh()->state);
        self::assertNull($incident->fresh()->acknowledged_at);
        self::assertNull($incident->fresh()->triggered_at);
    }

    public function test_acknowledgement_and_its_event_are_atomic(): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));
        $failed = false;
        IncidentEvent::creating(function (IncidentEvent $event) use (&$failed): void {
            if (! $failed && $event->event_type === 'acknowledged') {
                $failed = true;
                throw new \RuntimeException('Simulated event persistence failure');
            }
        });

        try {
            app(IncidentLifecycleService::class)->acknowledge($incident);
            self::fail('Event persistence failure should abort acknowledgement.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated event persistence failure', $exception->getMessage());
        }

        self::assertSame(IncidentState::Active, $incident->fresh()->state);
        self::assertNull($incident->fresh()->acknowledged_at);
        self::assertSame(0, $incident->events()->where('event_type', 'acknowledged')->count());
    }

    public function test_batch_activation_checks_the_current_episode_and_requirements(): void
    {
        $policy = $this->policy(['trigger_after_seconds' => 60, 'down_observations' => 2]);
        $device = $this->device();
        $ready = $this->incident($policy, $this->downPort($device), ['state' => IncidentState::Pending, 'triggered_at' => null, 'context_json' => ['observation_count' => 2]]);
        $tooEarly = $this->incident($policy, $this->downPort($device), ['state' => IncidentState::Pending, 'first_seen_at' => now(), 'triggered_at' => null, 'context_json' => ['observation_count' => 2]]);
        $unconfirmed = $this->incident($policy, $this->downPort($device), ['state' => IncidentState::Pending, 'triggered_at' => null, 'context_json' => ['observation_count' => 1]]);
        $changed = $this->incident($policy, $this->downPort($device), ['state' => IncidentState::Pending, 'triggered_at' => null, 'context_json' => ['observation_count' => 2]]);
        $oldSnapshot = $changed->fresh();
        $changed->update(['episode_uuid' => (string) Str::uuid()]);

        self::assertSame(1, app(IncidentLifecycleService::class)->activateManyIfEligible([$ready, $tooEarly, $unconfirmed, $oldSnapshot]));
        self::assertSame(IncidentState::Active, $ready->fresh()->state);
        self::assertNotNull($ready->fresh()->triggered_at);
        foreach ([$tooEarly, $unconfirmed, $changed] as $pending) {
            self::assertSame(IncidentState::Pending, $pending->fresh()->state);
            self::assertNull($pending->fresh()->triggered_at);
        }
        self::assertSame(1, $ready->events()->where('event_type', 'activated')->count());
        self::assertSame(0, app(IncidentLifecycleService::class)->activateManyIfEligible([$ready]));
        self::assertSame(1, $ready->events()->where('event_type', 'activated')->count());
    }
}
