<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Command;

use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * Storm control: when many interfaces on the same device go down together, a
 * single "device down" digest is sent instead of one SMS per interface.
 */
class DeviceDigestTest extends IntegrationTestCase
{
    public function test_many_interfaces_on_one_device_send_a_single_digest(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('aggregate_threshold', 3);
        $this->settings->put('aggregate_window_seconds', 3600);

        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        $incidents = collect(range(1, 5))->map(fn () => $this->incident($policy, $this->downPort($device)));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(1, DeliveryLog::where('phase', 'digest')->count());
        self::assertSame(0, DeliveryLog::where('phase', 'trigger')->count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['message'], '5 interfaces down'));

        foreach ($incidents as $incident) {
            $fresh = $incident->fresh();
            self::assertNotEmpty($fresh->context_json['trigger_notified_via_digest'] ?? null);
            self::assertTrue($fresh->events()->where('event_type', 'digested')->exists());
        }
    }

    public function test_a_device_below_the_threshold_notifies_per_interface(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('aggregate_threshold', 3);
        $this->settings->put('aggregate_window_seconds', 3600);

        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        $this->incident($policy, $this->downPort($device));
        $this->incident($policy, $this->downPort($device));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(0, DeliveryLog::where('phase', 'digest')->count());
        self::assertSame(2, DeliveryLog::where('phase', 'trigger')->count());
    }

    public function test_aggregation_disabled_notifies_per_interface(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        // aggregate_threshold defaults to 0 (disabled) — do not set it.

        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        collect(range(1, 4))->each(fn () => $this->incident($policy, $this->downPort($device)));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(0, DeliveryLog::where('phase', 'digest')->count());
        self::assertSame(4, DeliveryLog::where('phase', 'trigger')->count());
    }

    public function test_only_devices_over_the_threshold_are_grouped(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('aggregate_threshold', 3);
        $this->settings->put('aggregate_window_seconds', 3600);

        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());

        // Device A trips four interfaces → grouped into one digest.
        $deviceA = $this->device();
        collect(range(1, 4))->each(fn () => $this->incident($policy, $this->downPort($deviceA)));

        // Device B trips a single interface → still notified individually.
        $deviceB = $this->device();
        $this->incident($policy, $this->downPort($deviceB));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(1, DeliveryLog::where('phase', 'digest')->count());
        self::assertSame(1, DeliveryLog::where('phase', 'trigger')->count());
        Http::assertSentCount(2);
    }

    public function test_a_pending_device_outage_is_activated_then_grouped(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('aggregate_threshold', 3);
        $this->settings->put('aggregate_window_seconds', 3600);

        // Trigger delay already elapsed, so the pre-pass activates them this run.
        $policy = $this->policy(['trigger_after_seconds' => 300]);
        $this->triggerAction($policy, $this->smsDestination());
        $device = $this->device();
        collect(range(1, 4))->each(fn () => $this->incident($policy, $this->downPort($device), [
            'state' => \LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState::Pending,
            'triggered_at' => null,
            'first_seen_at' => now()->subMinutes(10),
        ]));

        $this->artisan('iapm:process-actions')->assertExitCode(0);

        self::assertSame(1, DeliveryLog::where('phase', 'digest')->count());
        self::assertSame(0, DeliveryLog::where('phase', 'trigger')->count());
    }
}
