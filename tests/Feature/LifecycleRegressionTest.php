<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\IngestionInbox;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class LifecycleRegressionTest extends IntegrationTestCase
{
    public function test_timestamp_free_recurrence_starts_a_new_episode_after_full_recovery(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);
        $active = $this->alertPayload($device, [$this->fault($port)]);
        unset($active['timestamp']);
        $recovery = array_replace($active, ['state' => 0, 'faults' => []]);

        $this->ingest($active)->assertOk();
        $episode = Incident::sole()->episode_uuid;
        $this->ingest($recovery)->assertOk();
        $this->ingest($active)->assertOk()->assertJsonPath('counts.activated', 1);

        self::assertSame(IncidentState::Active, Incident::sole()->state);
        self::assertNotSame($episode, Incident::sole()->episode_uuid);
        $this->ingest($active)->assertOk()->assertJsonPath('counts.ignored', 1);
    }

    public function test_async_down_up_down_in_one_retry_window_preserves_both_episodes(): void
    {
        config(['iapm.ingestion.async_threshold' => 1, 'iapm.ingestion.async_recovery' => true]);
        $this->defaultPolicy();
        $device = $this->device();
        $active = $this->alertPayload($device, [$this->fault($this->downPort($device))]);
        unset($active['timestamp']);
        $recovery = array_replace($active, ['state' => 0, 'faults' => []]);

        $this->ingest($active)->assertStatus(202);
        $this->ingest($recovery)->assertStatus(202);
        $this->ingest($active)->assertStatus(202);
        self::assertSame(3, IngestionInbox::count());
        $this->artisan('iapm:drain-ingestion', ['--limit' => 3])->assertExitCode(0);

        self::assertSame(IncidentState::Active, Incident::sole()->state);
        self::assertSame(1, Incident::sole()->events()->where('event_type', 'reopened')->count());
        self::assertSame(1, Incident::sole()->events()->where('event_type', 'recovered')->count());
    }

    public function test_async_recurrence_after_completed_recovery_is_not_an_old_inbox_retry(): void
    {
        config(['iapm.ingestion.async_threshold' => 1, 'iapm.ingestion.async_recovery' => true]);
        $this->defaultPolicy();
        $device = $this->device();
        $active = $this->alertPayload($device, [$this->fault($this->downPort($device))]);
        unset($active['timestamp']);
        $recovery = array_replace($active, ['state' => 0, 'faults' => []]);

        $this->ingest($active)->assertStatus(202);
        $this->artisan('iapm:drain-ingestion')->assertExitCode(0);
        $episode = Incident::sole()->episode_uuid;
        $this->ingest($recovery)->assertStatus(202);
        $this->artisan('iapm:drain-ingestion')->assertExitCode(0);
        $this->ingest($active)->assertStatus(202);
        $this->artisan('iapm:drain-ingestion')->assertExitCode(0);

        self::assertSame(IncidentState::Active, Incident::sole()->state);
        self::assertNotSame($episode, Incident::sole()->episode_uuid);
    }

    public function test_a_retry_after_terminal_abandonment_creates_replayable_work(): void
    {
        config(['iapm.ingestion.async_threshold' => 1]);
        $this->defaultPolicy();
        $device = $this->device();
        $active = $this->alertPayload($device, [$this->fault($this->downPort($device))]);
        $this->ingest($active)->assertStatus(202);
        IngestionInbox::sole()->update(['status' => 'dead', 'available_at' => null]);

        $this->ingest($active)->assertStatus(202);
        self::assertSame(2, IngestionInbox::count());
        self::assertSame(1, IngestionInbox::where('status', 'pending')->count());
        $this->artisan('iapm:drain-ingestion')->assertExitCode(0);
        self::assertSame(IncidentState::Active, Incident::sole()->state);
    }

    public function test_continued_observation_preserves_activation_time_and_due_escalation(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->defaultPolicy(['trigger_after_seconds' => 60]);
        $action = $this->triggerAction($policy, $this->smsDestination(), ['phase' => 'escalation', 'delay_seconds' => 120]);
        $device = $this->device();
        $port = $this->downPort($device);
        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();
        $this->travel(61)->seconds();
        $this->artisan('iapm:reconcile')->assertExitCode(0);
        $activatedAt = Incident::sole()->triggered_at->toDateTimeString();

        $this->travel(121)->seconds();
        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();
        self::assertSame(IncidentState::Active, Incident::sole()->state);
        self::assertSame($activatedAt, Incident::sole()->triggered_at->toDateTimeString());
        $this->artisan('iapm:process-actions')->assertExitCode(0);
        self::assertTrue(Incident::sole()->deliveries()->where('policy_action_id', $action->id)->where('status', 'sent')->exists());
    }

    public function test_per_port_recovery_requires_every_supplied_source_identifier_to_match(): void
    {
        $policy = $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);
        $incident = $this->incident($policy, $port);

        $this->ingest($this->alertPayload($device, [$this->fault($port)], 0, ['alert_uid' => 'unrelated-alert']))
            ->assertOk()->assertJsonPath('counts.recovered', 0);

        self::assertSame(IncidentState::Active, $incident->fresh()->state);
    }

    public function test_unpoliced_per_port_recovery_still_requires_matching_source_identifiers(): void
    {
        $device = $this->device();
        $port = $this->downPort($device);
        $incident = $this->incident($this->policy(), $port);
        $this->ingest($this->alertPayload($device, [$this->fault($port)], 0, ['alert_uid' => 'unrelated-alert']))
            ->assertOk()->assertJsonPath('counts.recovered', 0);
        self::assertSame(IncidentState::Active, $incident->fresh()->state);
    }

    public function test_unpoliced_timestamp_free_recurrence_also_starts_a_new_episode(): void
    {
        $device = $this->device();
        $active = $this->alertPayload($device, [$this->fault($this->downPort($device))]);
        unset($active['timestamp']);
        $this->ingest($active)->assertOk();
        $episode = Incident::sole()->episode_uuid;
        $this->ingest(array_replace($active, ['state' => 0, 'faults' => []]))->assertOk();
        $this->ingest($active)->assertOk()->assertJsonPath('counts.suppressed', 1);

        self::assertSame(IncidentState::Suppressed, Incident::sole()->state);
        self::assertNotSame($episode, Incident::sole()->episode_uuid);
    }

    public function test_continued_observation_preserves_the_single_flap_notice_guard(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->defaultPolicy(['flap_threshold' => 2, 'flap_window_seconds' => 600]);
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        $port = $this->downPort($device);
        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();
        $incident = Incident::sole();
        foreach (range(1, 2) as $cycle) {
            $incident->events()->create(['event_type' => 'reopened', 'event_message' => 'Previous cycle']);
        }
        $this->artisan('iapm:process-actions')->assertExitCode(0);
        $this->travel(1)->seconds();
        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();
        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(1, $incident->deliveries()->where('phase', 'flapping')->where('status', 'sent')->count());
        self::assertSame(1, $incident->events()->where('event_type', 'flapping')->count());
    }

    public function test_recovery_hold_down_does_not_overwrite_a_concurrent_newer_down_observation(): void
    {
        $this->defaultPolicy(['recovery_after_seconds' => 600]);
        $device = $this->device();
        $port = $this->downPort($device);
        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();
        $incident = Incident::sole();
        $newerSourceAt = now()->addMinutes(2)->toIso8601String();
        $interleaved = false;
        Incident::retrieved(function () use ($incident, $newerSourceAt, &$interleaved): void {
            if (! $interleaved) {
                $interleaved = true;
                $incident->update(['context_json' => array_replace((array) $incident->context_json, [
                    'last_source_event_at' => $newerSourceAt,
                    'assignment_receivers' => ['current-on-call'],
                    'flap_notified' => 'already-notified',
                ])]);
            }
        });
        try {
            $this->ingest($this->alertPayload($device, [], 0, ['timestamp' => now()->addMinute()->toIso8601String()]))
                ->assertOk()->assertJsonPath('counts.pending', 0);
        } finally {
            Incident::getEventDispatcher()->forget('eloquent.retrieved: '.Incident::class);
        }

        $context = $incident->fresh()->context_json;
        self::assertSame(IncidentState::Active, $incident->fresh()->state);
        self::assertSame($newerSourceAt, $context['last_source_event_at']);
        self::assertSame(['current-on-call'], $context['assignment_receivers']);
        self::assertSame('already-notified', $context['flap_notified']);
        self::assertArrayNotHasKey('up_seen_at', $context);
    }

    public function test_recovery_hold_down_merges_context_added_after_the_recovery_scan(): void
    {
        $this->defaultPolicy(['recovery_after_seconds' => 600]);
        $device = $this->device();
        $port = $this->downPort($device);
        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();
        $incident = Incident::sole();
        $interleaved = false;
        Incident::retrieved(function () use ($incident, &$interleaved): void {
            if (! $interleaved) {
                $interleaved = true;
                $incident->update(['context_json' => array_replace((array) $incident->context_json, [
                    'assignment_receivers' => ['new-on-call'],
                    'flap_notified' => 'keep-this-marker',
                ])]);
            }
        });
        try {
            $this->ingest($this->alertPayload($device, [], 0, ['timestamp' => now()->addMinute()->toIso8601String()]))
                ->assertOk()->assertJsonPath('counts.pending', 1);
        } finally {
            Incident::getEventDispatcher()->forget('eloquent.retrieved: '.Incident::class);
        }

        $context = $incident->fresh()->context_json;
        self::assertSame(['new-on-call'], $context['assignment_receivers']);
        self::assertSame('keep-this-marker', $context['flap_notified']);
        self::assertArrayHasKey('up_seen_at', $context);
    }
}
