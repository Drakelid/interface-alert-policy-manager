<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Command;

use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class ProcessActionsCommandTest extends IntegrationTestCase
{
    public function test_a_pending_incident_is_activated_when_its_requirements_are_met(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy(['trigger_after_seconds' => 300]);
        $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Pending, 'triggered_at' => null, 'first_seen_at' => now()->subMinutes(10)]);

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        $incident->refresh();
        self::assertSame(IncidentState::Active, $incident->state);
        self::assertTrue($incident->events()->where('event_type', 'activated')->exists());
        self::assertSame(1, DeliveryLog::count());
    }

    public function test_a_pending_incident_below_its_trigger_delay_is_not_notified(): void
    {
        Http::fake();
        $policy = $this->policy(['trigger_after_seconds' => 3600]);
        $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Pending, 'triggered_at' => null, 'first_seen_at' => now()]);

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(IncidentState::Pending, $incident->fresh()->state);
        self::assertSame(0, DeliveryLog::count());
        Http::assertNothingSent();
    }

    public function test_action_processing_is_idempotent(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions')->assertExitCode(0);
        $this->artisan('iapm:process-actions')->assertExitCode(0);
        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(1, DeliveryLog::where('status', 'sent')->count());
        Http::assertSentCount(1);
    }

    public function test_a_reminder_repeats_at_the_configured_interval_up_to_the_maximum(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination(), ['phase' => 'reminder', 'repeat_seconds' => 900, 'maximum_sends' => 2]);
        $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions');
        self::assertSame(1, DeliveryLog::where('phase', 'reminder')->count());

        // Too soon for the repeat interval.
        $this->artisan('iapm:process-actions');
        self::assertSame(1, DeliveryLog::where('phase', 'reminder')->count());

        $this->travel(901)->seconds();
        $this->artisan('iapm:process-actions');
        self::assertSame(2, DeliveryLog::where('phase', 'reminder')->count());

        // The maximum number of sends has now been reached.
        $this->travel(901)->seconds();
        $this->artisan('iapm:process-actions');
        self::assertSame(2, DeliveryLog::where('phase', 'reminder')->count());
    }

    public function test_an_escalation_waits_for_its_delay_after_the_trigger(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $destination = $this->smsDestination();
        $this->triggerAction($policy, $destination, ['phase' => 'escalation', 'delay_seconds' => 1800]);
        $this->incident($policy, $this->downPort($this->device()), ['triggered_at' => now()]);

        $this->artisan('iapm:process-actions');
        self::assertSame(0, DeliveryLog::count());

        $this->travel(1801)->seconds();
        $this->artisan('iapm:process-actions');
        self::assertSame(1, DeliveryLog::where('phase', 'escalation')->count());
    }

    public function test_a_recovery_notification_is_sent_only_when_the_policy_enables_it(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy(['notify_recovery' => false]);
        $destination = $this->smsDestination();
        $this->triggerAction($policy, $destination, ['phase' => 'recovery']);
        $incident = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Recovered, 'recovered_at' => now()]);

        $this->artisan('iapm:process-actions');
        self::assertSame(0, DeliveryLog::count());

        $incident->policy->update(['notify_recovery' => true]);
        $this->artisan('iapm:process-actions');

        self::assertSame(1, DeliveryLog::where('phase', 'recovery')->count());
        Http::assertSent(fn ($request) => str_contains($request['message'], 'RECOVERED: Interface restored'));
    }

    public function test_an_acknowledged_incident_uses_the_acknowledged_phase(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination(), ['phase' => 'acknowledged']);
        $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Acknowledged, 'acknowledged_at' => now()]);

        $this->artisan('iapm:process-actions');

        self::assertSame(1, DeliveryLog::where('phase', 'acknowledged')->count());
    }

    public function test_a_long_recovered_incident_is_not_reprocessed(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy(['notify_recovery' => true]);
        $this->triggerAction($policy, $this->smsDestination(), ['phase' => 'recovery']);
        // Recovered 3 days ago — outside the 48h window, so it must not be scanned or
        // re-notified (recovered rows are retained for a year).
        $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Recovered, 'recovered_at' => now()->subDays(3)]);

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(0, DeliveryLog::where('phase', 'recovery')->count());
    }

    public function test_a_zero_time_budget_stops_before_sending(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        config(['iapm.processing.max_seconds' => 0]);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        // The wall-clock budget is exhausted immediately, so nothing is dispatched;
        // the backlog is left for the next scheduled run.
        self::assertSame(0, DeliveryLog::count());
        Http::assertNothingSent();
    }

    public function test_force_resends_a_specific_action(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());
        $incident = $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions');
        $this->artisan("iapm:process-actions --incident={$incident->id} --action={$action->id} --force");

        self::assertSame(2, DeliveryLog::where('status', 'sent')->count());
    }

    public function test_a_receiver_override_on_the_action_wins(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy(['default_receiver' => 'policy-receiver']);
        $this->triggerAction($policy, $this->smsDestination(), ['receivers_json' => ['action-receiver-a', 'action-receiver-b']]);
        $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions');

        self::assertSame(['[REDACTED]', '[REDACTED]'], DeliveryLog::orderBy('id')->get()->map(fn ($log) => json_decode((string) $log->request_payload_redacted, true)['receiver'])->all());
        Http::assertSent(fn ($request) => in_array($request['receiver'], ['action-receiver-a', 'action-receiver-b'], true));
        Http::assertSentCount(2);
    }
}
