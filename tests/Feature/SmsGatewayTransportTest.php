<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Transports\SmsGatewayTransport;

class SmsGatewayTransportTest extends IntegrationTestCase
{
    private function configuration(array $overrides = []): array
    {
        return array_merge([
            'url' => 'http://127.0.0.1:5000/api/v10/messages/send',
            'username' => 'gateway-user',
            'password' => 'gateway-password',
            'mode' => 'json',
            'verify_tls' => true,
            'allow_private_networks' => true,
        ], $overrides);
    }

    public function test_the_json_body_contains_exactly_receiver_and_message(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $result = app(SmsGatewayTransport::class)->send($this->configuration(), 'PERSON', 'Rendered alert message');

        self::assertTrue($result->successful);
        Http::assertSent(function (Request $request): bool {
            self::assertSame(['receiver' => 'PERSON', 'message' => 'Rendered alert message'], $request->data());

            return true;
        });
    }

    public function test_unsafe_custom_headers_are_stripped(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        app(SmsGatewayTransport::class)->send($this->configuration([
            'headers' => ['X-Tenant' => 'core', 'Authorization' => 'Bearer leaked', 'Host' => 'evil.example.com'],
        ]), 'PERSON', 'message');

        Http::assertSent(function (Request $request): bool {
            self::assertSame('core', $request->header('X-Tenant')[0]);
            self::assertSame('Basic '.base64_encode('gateway-user:gateway-password'), $request->header('Authorization')[0]);
            self::assertNotSame('evil.example.com', $request->header('Host')[0] ?? null);

            return true;
        });
    }

    public function test_a_missing_username_omits_basic_authentication(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        app(SmsGatewayTransport::class)->send($this->configuration(['username' => null, 'password' => null]), 'PERSON', 'message');

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
    }

    public function test_a_2xx_response_is_success_unless_the_body_reports_an_error(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['ok' => true], 200)
            ->push(['ok' => false], 200)
            ->push(['error' => 'no such receiver'], 200)
            ->push(['error' => null], 200)]);
        self::assertTrue(app(SmsGatewayTransport::class)->send($this->configuration(), 'PERSON', 'message')->successful);

        self::assertFalse(app(SmsGatewayTransport::class)->send($this->configuration(), 'PERSON', 'message')->successful);

        self::assertFalse(app(SmsGatewayTransport::class)->send($this->configuration(), 'PERSON', 'message')->successful);

        self::assertTrue(app(SmsGatewayTransport::class)->send($this->configuration(), 'PERSON', 'message')->successful, 'A null error field is not a failure.');
    }

    public function test_json_whitespace_does_not_turn_an_empty_error_into_failure(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('{"ok": true, "error": null}', 200)
            ->push('{"ok": true, "error": ""}', 200)
            ->push("{\n  \"success\": true,\n  \"error\": null\n}", 200)]);

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue(app(SmsGatewayTransport::class)->send($this->configuration(), 'PERSON', 'message')->successful);
        }
    }

    public function test_failure_after_the_log_truncation_boundary_is_still_detected(): void
    {
        Http::fake(['*' => Http::response(json_encode(['detail' => str_repeat('x', 5000), 'error' => 'receiver rejected']), 200)]);

        $result = app(SmsGatewayTransport::class)->send($this->configuration(), 'PERSON', 'message');

        self::assertFalse($result->successful);
        self::assertSame(4096, mb_strlen((string) $result->response));
    }

    public function test_json_looking_text_inside_a_message_is_not_a_failure_status(): void
    {
        Http::fake(['*' => Http::response(json_encode(['message' => 'The literal text "error": "example" is harmless.']), 200)]);

        self::assertTrue(app(SmsGatewayTransport::class)->send($this->configuration(), 'PERSON', 'message')->successful);
    }

    public function test_response_echoes_are_redacted_before_truncation(): void
    {
        $message = "Customer Ægir / interface \"uplink\"\n".str_repeat('private detail ', 1000);
        Http::fake(['*' => Http::response(json_encode(['receiver' => 'PERSON', 'message' => $message, 'password' => 'gateway-password']), 200)]);

        $result = app(SmsGatewayTransport::class)->send($this->configuration(), 'PERSON', $message);

        self::assertTrue($result->successful);
        self::assertStringNotContainsString('Customer', (string) $result->response);
        self::assertStringNotContainsString('private detail', (string) $result->response);
        self::assertStringNotContainsString('PERSON', (string) $result->response);
        self::assertStringNotContainsString('gateway-password', (string) $result->response);
    }

    public function test_exception_echoes_are_redacted_before_truncation(): void
    {
        $message = 'customer-details '.str_repeat('private detail ', 1000);
        Http::fake(fn () => throw new \RuntimeException('Gateway echoed '.$message.' gateway-password'));

        $result = app(SmsGatewayTransport::class)->send($this->configuration(), 'PERSON', $message);

        self::assertFalse($result->successful);
        self::assertStringNotContainsString('customer-details', (string) $result->error);
        self::assertStringNotContainsString('private detail', (string) $result->error);
        self::assertStringNotContainsString('gateway-password', (string) $result->error);
    }

    public function test_a_transport_exception_never_leaks_the_password(): void
    {
        Http::fake(fn () => throw new \RuntimeException('failed to connect with password=gateway-password'));

        $result = app(SmsGatewayTransport::class)->send($this->configuration(), 'PERSON', 'message');

        self::assertFalse($result->successful);
        self::assertStringNotContainsString('gateway-password', (string) $result->error);
        self::assertStringContainsString('[REDACTED]', (string) $result->error);
    }

    public function test_a_response_body_is_truncated_before_it_is_stored(): void
    {
        Http::fake(['*' => Http::response(str_repeat('x', 20000), 500)]);

        $result = app(SmsGatewayTransport::class)->send($this->configuration(), 'PERSON', 'message');

        self::assertFalse($result->successful);
        self::assertSame(4096, mb_strlen((string) $result->response));
    }

    public function test_a_blocked_url_prevents_any_request(): void
    {
        Http::fake();

        $result = app(SmsGatewayTransport::class)->send($this->configuration(['allow_private_networks' => false]), 'PERSON', 'message');

        self::assertFalse($result->successful);
        Http::assertNothingSent();
    }
}
