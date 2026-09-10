<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\HealthService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\IngestionRejectionLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * A production fault where LibreNMS raised interface alerts for three hours and
 * IAPM recorded nothing: the poller handling those devices could not reach the
 * ingestion URL, so the POST never happened. IAPM behaved correctly throughout
 * — and `iapm:health` stayed green, which is the part that was wrong.
 *
 * These cover the three gaps that let a silent alert path stay invisible: no
 * dead-man's switch on the input, refusals that left no trace, and a liveness
 * stamp only ingestion ever wrote.
 */
class SilentAlertPathTest extends IntegrationTestCase
{
    /** The rule id the incident fixture records as having delivered to IAPM. */
    private const DELIVERING_RULE = 14;

    // ---------------------------------------------------------------------
    // 1. Dead-man's switch on the input path.
    // ---------------------------------------------------------------------

    public function test_a_quiet_fleet_is_not_mistaken_for_a_broken_alert_path(): void
    {
        // Three days without an alert is what a healthy network looks like. The
        // check must key on disagreement, never on silence alone.
        $this->settings->put('last_ingestion_at', now()->subDays(3)->toIso8601String());
        $this->incident($this->defaultPolicy(), $this->downPort($this->device()));

        self::assertTrue($this->ingestionCheck()['ok']);
    }

    public function test_alerts_raised_but_never_delivered_turn_the_check_red(): void
    {
        $device = $this->device();
        $this->settings->put('last_ingestion_at', now()->subHours(2)->toIso8601String());
        $this->incident($this->defaultPolicy(), $this->downPort($device));
        $this->alertLogEntry((int) $device->device_id, self::DELIVERING_RULE, now());

        $check = $this->ingestionCheck();

        self::assertFalse($check['ok']);
        self::assertStringContainsString('not arriving', $check['detail']);
    }

    public function test_the_operator_is_told_which_way_to_look(): void
    {
        $device = $this->device();
        $this->settings->put('last_ingestion_at', now()->subHours(2)->toIso8601String());
        $this->incident($this->defaultPolicy(), $this->downPort($device));
        $this->alertLogEntry((int) $device->device_id, self::DELIVERING_RULE, now());

        $this->artisan('iapm:health')->expectsOutputToContain('[FAIL] Receiving alerts from LibreNMS');
    }

    public function test_a_rule_that_has_never_delivered_to_iapm_cannot_trip_the_switch(): void
    {
        // Most LibreNMS rules have nothing to do with IAPM. Only a rule that has
        // actually delivered before is evidence of a wiring that has stopped.
        $device = $this->device();
        $this->settings->put('last_ingestion_at', now()->subHours(2)->toIso8601String());
        $this->incident($this->defaultPolicy(), $this->downPort($device));
        $this->alertLogEntry((int) $device->device_id, 9999, now());

        self::assertTrue($this->ingestionCheck()['ok']);
    }

    public function test_an_alert_still_inside_the_grace_window_is_not_yet_a_fault(): void
    {
        // LibreNMS evaluates every minute and the ingestion heartbeat is throttled;
        // an alert logged seconds ago has not had its chance to arrive yet.
        $device = $this->device();
        $this->settings->put('last_ingestion_at', now()->toIso8601String());
        $this->incident($this->defaultPolicy(), $this->downPort($device));
        $this->alertLogEntry((int) $device->device_id, self::DELIVERING_RULE, now());

        self::assertTrue($this->ingestionCheck()['ok']);
    }

    public function test_an_install_that_has_never_ingested_is_a_setup_task_not_a_failure(): void
    {
        $check = $this->ingestionCheck();

        self::assertTrue($check['ok']);
        self::assertStringContainsString('Setup Helper', $check['detail']);
    }

    // ---------------------------------------------------------------------
    // 2. Refusals that used to leave no trace.
    // ---------------------------------------------------------------------

    public function test_each_refusal_reason_is_logged_once_per_source_per_window(): void
    {
        $codes = [];
        Log::shouldReceive('channel')->with('iapm')->andReturnSelf();
        Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$codes): void {
            self::assertSame('Ingestion request rejected.', $message);
            $codes[] = $context['code'];
        });

        $rejections = app(IngestionRejectionLog::class);
        $rejections->record('unsupported_media_type', '10.0.0.1');
        $rejections->record('unsupported_media_type', '10.0.0.1'); // throttled: same reason, same source
        $rejections->record('payload_too_large', '10.0.0.1');      // a different reason still speaks
        $rejections->record('unsupported_media_type', '10.0.0.2'); // so does a different source

        self::assertSame(['unsupported_media_type', 'payload_too_large', 'unsupported_media_type'], $codes);
    }

    public function test_an_alert_for_an_unknown_device_no_longer_vanishes_quietly(): void
    {
        $this->ingest($this->payloadForMissingDevice())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'device_not_found');

        self::assertTrue($this->wasRecorded('device_not_found'));
    }

    public function test_an_uncorrelatable_recovery_no_longer_vanishes_quietly(): void
    {
        // Without identifiers this recovery can never close anything, so the
        // incidents it was meant to clear would stay open with no explanation.
        $this->ingest(['device_id' => $this->device()->device_id, 'state' => 0, 'faults' => []])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'correlation_required');

        self::assertTrue($this->wasRecorded('correlation_required'));
    }

    // ---------------------------------------------------------------------
    // 3. A liveness stamp only ingestion ever wrote.
    // ---------------------------------------------------------------------

    public function test_reconciliation_refreshes_the_liveness_stamp_of_a_still_down_interface(): void
    {
        // LibreNMS re-notifies an open alert with an unchanging timestamp, so
        // ingestion dedupes every repeat and stops touching the row. Reconcile
        // confirming the port is still down is then the only evidence there is.
        $incident = $this->incident($this->defaultPolicy(), $this->downPort($this->device()), ['last_seen_at' => now()->subHours(6)]);

        $this->artisan('iapm:reconcile')->assertExitCode(0);

        self::assertTrue($incident->fresh()->last_seen_at->greaterThan(now()->subMinute()));
    }

    public function test_the_refresh_is_throttled_so_a_long_outage_is_not_rewritten_every_minute(): void
    {
        $stamp = now()->subSeconds(30);
        $incident = $this->incident($this->defaultPolicy(), $this->downPort($this->device()), ['last_seen_at' => $stamp]);

        $this->artisan('iapm:reconcile')->assertExitCode(0);

        self::assertSame($stamp->format('Y-m-d H:i:s'), $incident->fresh()->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_a_dry_run_never_writes_a_liveness_stamp(): void
    {
        $stamp = now()->subHours(6);
        $incident = $this->incident($this->defaultPolicy(), $this->downPort($this->device()), ['last_seen_at' => $stamp]);

        $this->artisan('iapm:reconcile', ['--dry-run' => true])->assertExitCode(0);

        self::assertSame($stamp->format('Y-m-d H:i:s'), $incident->fresh()->last_seen_at->format('Y-m-d H:i:s'));
    }

    // ---------------------------------------------------------------------

    /** @return array{key:string,label:string,ok:bool,detail:string} */
    private function ingestionCheck(): array
    {
        return collect(app(HealthService::class)->checks())->firstWhere('key', 'ingestion');
    }

    private function alertLogEntry(int $deviceId, int $ruleId, \DateTimeInterface $loggedAt): void
    {
        DB::table('alert_log')->insert([
            'rule_id' => $ruleId,
            'device_id' => $deviceId,
            'state' => 1,
            'details' => '',
            'time_logged' => $loggedAt->format('Y-m-d H:i:s'),
        ]);
    }

    /** The rejection log throttles on a deterministic key, so its presence is the receipt. */
    private function wasRecorded(string $code): bool
    {
        return RateLimiter::tooManyAttempts('iapm:ingest-reject:'.$code.':'.hash('sha256', '127.0.0.1'), 1);
    }

    /** @return array<string, mixed> */
    private function payloadForMissingDevice(): array
    {
        return [
            'alert_uid' => '18432',
            'alert_id' => 572,
            'rule_id' => self::DELIVERING_RULE,
            'device_id' => 999999,
            'state' => 1,
            'severity' => 'critical',
            'timestamp' => now()->toIso8601String(),
            'faults' => [],
        ];
    }
}
