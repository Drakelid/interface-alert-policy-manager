<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use InvalidArgumentException;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlGuardTest extends TestCase
{
    public function test_file_urls_are_blocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UrlGuard)->assertAllowed('file:///etc/passwd');
    }

    public function test_credentials_in_urls_are_blocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UrlGuard)->assertAllowed('https://user:pass@example.com/hook');
    }

    public function test_credentials_in_the_query_string_are_blocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UrlGuard)->assertAllowed('https://hooks.example.com/notify?token=secret');
    }

    public function test_private_ipv4_addresses_are_blocked_by_default(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UrlGuard)->assertAllowed('http://127.0.0.1/hook');
    }

    #[DataProvider('blockedIpv6Literals')]
    public function test_private_and_reserved_ipv6_literals_are_blocked(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new UrlGuard)->assertAllowed($url);
    }

    public static function blockedIpv6Literals(): array
    {
        return [
            'loopback' => ['http://[::1]/hook'],
            'unspecified' => ['http://[::]/hook'],
            'unique local fc00::/7' => ['http://[fd00::1]/hook'],
            'unique local fc' => ['http://[fc00::1]/hook'],
            'link local fe80::/10' => ['http://[fe80::1]/hook'],
            'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/hook'],
            'ipv4-mapped private' => ['http://[::ffff:10.0.0.1]/hook'],
        ];
    }

    public function test_a_public_ipv6_literal_is_allowed(): void
    {
        (new UrlGuard)->assertAllowed('http://[2606:4700:4700::1111]/hook');
        $this->addToAssertionCount(1);
    }

    public function test_a_private_address_is_allowed_when_explicitly_permitted(): void
    {
        (new UrlGuard)->assertAllowed('http://127.0.0.1/hook', allowPrivate: true);
        (new UrlGuard)->assertAllowed('http://[::1]/hook', allowPrivate: true);
        $this->addToAssertionCount(2);
    }

    public function test_pinned_options_disable_redirects_and_pin_an_ipv6_literal(): void
    {
        $options = (new UrlGuard)->pinnedOptions('http://[2606:4700:4700::1111]:8443/hook', allowPrivate: false);

        self::assertFalse($options['allow_redirects']);
        if (defined('CURLOPT_RESOLVE')) {
            self::assertSame(['[2606:4700:4700::1111]:8443:[2606:4700:4700::1111]'], $options['curl'][CURLOPT_RESOLVE]);
        }
    }
}
