<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\TransportManager;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class WebhookSsrfTest extends IntegrationTestCase
{
    public function test_a_blocked_url_is_never_requested(): void
    {
        Http::fake();

        $blocked = [
            'link-local metadata service' => 'http://169.254.169.254/latest/meta-data/',
            'loopback' => 'http://127.0.0.1/internal',
            'private range' => 'http://10.0.0.5/internal',
            'file scheme' => 'file:///etc/passwd',
            'gopher scheme' => 'gopher://127.0.0.1:11211/_stats',
            'no host' => 'https:///path',
            'credentials in userinfo' => 'https://user:secret@example.com/hook',
        ];

        foreach ($blocked as $description => $url) {
            $destination = $this->webhook(['url' => $url]);
            $result = app(TransportManager::class)->for('generic_webhook')->send((array) $destination->configuration_encrypted, 'noc', 'message');

            self::assertFalse($result->successful, "Expected $description to be blocked.");
        }

        Http::assertNothingSent();
    }

    public function test_credentials_in_the_query_string_are_rejected(): void
    {
        Http::fake();
        $destination = $this->webhook(['url' => 'https://hooks.example.com/notify?token=secret-value']);

        $result = app(TransportManager::class)->for('generic_webhook')->send((array) $destination->configuration_encrypted, 'noc', 'message');

        self::assertFalse($result->successful);
        self::assertStringContainsString('Credentials are not allowed', (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_a_private_address_is_allowed_when_explicitly_permitted(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $destination = $this->webhook(['url' => 'http://10.0.0.5/hook', 'allow_private_networks' => true]);

        $result = app(TransportManager::class)->for('generic_webhook')->send((array) $destination->configuration_encrypted, 'noc', 'message');

        self::assertTrue($result->successful);
        Http::assertSentCount(1);
    }

    public function test_the_error_message_of_a_blocked_send_does_not_leak_the_bearer_token(): void
    {
        Http::fake();
        $destination = $this->webhook(['url' => 'http://127.0.0.1/hook', 'bearer_token' => 'super-secret-token']);

        $result = app(TransportManager::class)->for('generic_webhook')->send((array) $destination->configuration_encrypted, 'noc', 'message');

        self::assertFalse($result->successful);
        self::assertStringNotContainsString('super-secret-token', (string) $result->error);
    }

    public function test_an_unsupported_destination_type_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(TransportManager::class)->for('carrier_pigeon');
    }

    private function webhook(array $configuration): Destination
    {
        return Destination::create([
            'name' => 'Webhook '.$this->faker->unique()->numberBetween(1, 99999),
            'type' => 'generic_webhook',
            'enabled' => true,
            'configuration_encrypted' => array_merge(['verify_tls' => true, 'allow_private_networks' => false], $configuration),
        ]);
    }
}
