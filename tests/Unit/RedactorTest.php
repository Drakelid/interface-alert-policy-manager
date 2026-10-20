<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_nested_secrets_are_redacted(): void
    {
        $v = (new Redactor)->array(['url' => 'https://example.test', 'auth' => ['password' => 'secret', 'token' => 'token']]);
        self::assertSame('[REDACTED]', $v['auth']['password']);
        self::assertSame('[REDACTED]', $v['auth']['token']);
    }

    public function test_text_is_truncated_and_redacted(): void
    {
        self::assertStringNotContainsString('hunter2', (new Redactor)->text('password=hunter2'));
    }

    public function test_text_redacts_bearer_tokens_and_url_userinfo(): void
    {
        $redacted = (new Redactor)->text('Bearer abc.def https://gateway-user:hunter2@example.test/send');

        self::assertStringNotContainsString('abc.def', $redacted);
        self::assertStringNotContainsString('gateway-user', $redacted);
        self::assertStringNotContainsString('hunter2', $redacted);
    }

    public function test_json_secrets_are_redacted_including_nested_and_non_string_values(): void
    {
        $redacted = (new Redactor)->text(json_encode([
            'auth' => ['password' => 'a "quoted" password', 'token' => 123456],
            'authorization' => 'Basic encoded-credentials',
            'api-key' => ['nested-secret'],
            'status' => 'failed',
        ]));
        $decoded = json_decode($redacted, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('[REDACTED]', $decoded['auth']['password']);
        self::assertSame('[REDACTED]', $decoded['auth']['token']);
        self::assertSame('[REDACTED]', $decoded['authorization']);
        self::assertSame('[REDACTED]', $decoded['api-key']);
        self::assertSame('failed', $decoded['status']);
    }

    public function test_json_secrets_inside_exception_text_are_redacted(): void
    {
        $redacted = (new Redactor)->text('Gateway replied: '.json_encode(['password' => 'a "quoted" secret']));

        self::assertStringNotContainsString('quoted', $redacted);
        self::assertStringNotContainsString('secret', $redacted);
        self::assertStringContainsString('[REDACTED]', $redacted);
    }

    public function test_a_long_plaintext_echo_is_removed_before_truncation(): void
    {
        $message = 'confidential-customer-detail '.str_repeat('sensitive data ', 1000);

        self::assertSame('echo: [REDACTED]', (new Redactor)->text('echo: '.$message, [$message]));
    }

    public function test_json_escaped_echoes_are_removed_before_truncation(): void
    {
        $message = "Customer Ægir / interface \"uplink\"\n".str_repeat('private detail ', 1000);
        $receiver = '+47 99998888';
        foreach ([0, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES] as $flags) {
            $body = json_encode(['receiver' => $receiver, 'message' => $message], $flags);
            $redacted = (new Redactor)->text($body, [$receiver, $message]);

            self::assertStringNotContainsString('99998888', $redacted);
            self::assertStringNotContainsString('Customer', $redacted);
            self::assertStringNotContainsString('private detail', $redacted);
            self::assertSame(['receiver' => '[REDACTED]', 'message' => '[REDACTED]'], json_decode($redacted, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function test_json_escaped_echo_inside_exception_text_is_removed(): void
    {
        $message = "Customer Ægir / interface \"uplink\"\n".str_repeat('private detail ', 1000);
        $body = 'Gateway replied: '.json_encode(['message' => $message]);
        $redacted = (new Redactor)->text($body, [$message]);

        self::assertStringNotContainsString('Customer', $redacted);
        self::assertStringNotContainsString('private detail', $redacted);
        self::assertStringContainsString('[REDACTED]', $redacted);
    }
}
