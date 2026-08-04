<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class SmsDeliveryTest extends IntegrationTestCase
{
    public function test_a_successful_send_posts_json_and_records_the_delivery(): void
    {
        Http::fake(['*' => Http::response(['status' => 'queued'], 200)]);
        $incident = $this->activeIncidentWithTriggerAction();

        Artisan::call('iapm:process-actions');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://127.0.0.1:5000/api/v10/messages/send'
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('gateway-user:gateway-password'))
                && $request['receiver'] === 'noc'
                && str_contains($request['message'], 'CRITICAL: Interface down');
        });

        $delivery = DeliveryLog::sole();
        self::assertSame('sent', $delivery->status);
        self::assertSame(200, $delivery->response_status);
        self::assertNotNull($delivery->sent_at);
        self::assertSame(1, (int) $incident->fresh()->notification_count);
    }

    public function test_the_delivery_log_never_contains_gateway_credentials_or_the_message_body(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->activeIncidentWithTriggerAction();

        Artisan::call('iapm:process-actions');

        $delivery = DeliveryLog::sole();
        $serialized = json_encode($delivery->toArray());
        self::assertStringNotContainsString('gateway-password', $serialized);
        self::assertStringNotContainsString('gateway-user', $serialized);
        self::assertStringContainsString('[MESSAGE REDACTED]', (string) $delivery->request_payload_redacted);
    }

    public function test_a_connection_timeout_is_recorded_as_a_failed_delivery(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));
        $incident = $this->activeIncidentWithTriggerAction();

        Artisan::call('iapm:process-actions');

        $delivery = DeliveryLog::sole();
        self::assertSame('failed', $delivery->status);
        self::assertNull($delivery->sent_at);
        self::assertStringContainsString('timed out', (string) $delivery->error_message);
        self::assertSame(0, (int) $incident->fresh()->notification_count);
        self::assertTrue($incident->events()->where('event_type', 'notification_failed')->exists());
    }

    public function test_a_non_2xx_response_is_recorded_with_a_truncated_body(): void
    {
        Http::fake(['*' => Http::response(str_repeat('e', 9000), 503)]);
        $this->activeIncidentWithTriggerAction();

        Artisan::call('iapm:process-actions');

        $delivery = DeliveryLog::sole();
        self::assertSame('failed', $delivery->status);
        self::assertSame(503, $delivery->response_status);
        self::assertLessThanOrEqual(4096, mb_strlen((string) $delivery->response_body_redacted));
    }

    public function test_a_2xx_response_whose_body_reports_an_error_is_treated_as_a_failure(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'reason' => 'unknown receiver'], 200)]);
        $this->activeIncidentWithTriggerAction();

        Artisan::call('iapm:process-actions');

        self::assertSame('failed', DeliveryLog::sole()->status);
    }

    public function test_a_failed_attempt_is_retried_up_to_the_configured_count(): void
    {
        Http::fake(['*' => Http::sequence()->push('unavailable', 503)->push('ok', 200)]);
        $this->activeIncidentWithTriggerAction(['retry_count' => 1]);

        Artisan::call('iapm:process-actions');

        self::assertSame(2, DeliveryLog::count());
        self::assertSame(['failed', 'sent'], DeliveryLog::orderBy('attempt')->pluck('status')->all());
        Http::assertSentCount(2);
    }

    public function test_an_unresolvable_receiver_fails_configuration_without_contacting_the_gateway(): void
    {
        Http::fake();
        $this->activeIncidentWithTriggerAction(['default_receiver' => '']);

        Artisan::call('iapm:process-actions');

        $delivery = DeliveryLog::sole();
        self::assertSame('failed_configuration', $delivery->status);
        self::assertStringContainsString('receiver', (string) $delivery->error_message);
        Http::assertNothingSent();
    }

    public function test_dry_run_records_the_intended_delivery_without_contacting_the_gateway(): void
    {
        Http::fake();
        $this->settings->put('dry_run', true);
        $incident = $this->activeIncidentWithTriggerAction();

        Artisan::call('iapm:process-actions');

        self::assertSame('dry_run', DeliveryLog::sole()->status);
        self::assertSame(0, (int) $incident->fresh()->notification_count);
        self::assertTrue($incident->events()->where('event_type', 'notification_suppressed')->exists());
        Http::assertNothingSent();
    }

    public function test_a_disabled_destination_fails_configuration(): void
    {
        Http::fake();
        $incident = $this->activeIncidentWithTriggerAction();
        $incident->policy->actions->first()->destination->update(['enabled' => false]);

        Artisan::call('iapm:process-actions');

        self::assertSame('failed_configuration', DeliveryLog::sole()->status);
        Http::assertNothingSent();
    }

    public function test_a_muted_incident_is_not_notified(): void
    {
        Http::fake();
        $incident = $this->activeIncidentWithTriggerAction();
        $incident->update(['muted_until' => now()->addHour()]);

        Artisan::call('iapm:process-actions');

        self::assertSame(0, DeliveryLog::count());
        Http::assertNothingSent();
    }

    public function test_a_policy_with_notifications_disabled_records_the_incident_but_never_delivers(): void
    {
        Http::fake();
        $incident = $this->activeIncidentWithTriggerAction();
        $incident->policy->update(['notifications_enabled' => false]);

        Artisan::call('iapm:process-actions');

        self::assertSame(0, DeliveryLog::count());
        Http::assertNothingSent();
    }

    public function test_a_trigger_notification_is_sent_only_once_without_a_repeat_interval(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->activeIncidentWithTriggerAction();

        Artisan::call('iapm:process-actions');
        Artisan::call('iapm:process-actions');

        self::assertSame(1, DeliveryLog::count());
        Http::assertSentCount(1);
    }

    public function test_form_mode_sends_form_encoded_fields(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->activeIncidentWithTriggerAction(['mode' => 'form']);

        Artisan::call('iapm:process-actions');

        Http::assertSent(fn (Request $request) => str_starts_with((string) $request->header('Content-Type')[0], 'application/x-www-form-urlencoded') && $request['receiver'] === 'noc');
    }

    private function activeIncidentWithTriggerAction(array $destinationConfiguration = [])
    {
        $policy = $this->policy();
        $destination = $this->smsDestination($destinationConfiguration);
        $this->triggerAction($policy, $destination);
        $device = $this->device();

        return $this->incident($policy, $this->downPort($device))->setRelation('policy', $policy);
    }
}
