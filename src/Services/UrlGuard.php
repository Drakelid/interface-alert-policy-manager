<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use InvalidArgumentException;

class UrlGuard
{
    public function assertAllowed(string $url, bool $allowPrivate = false): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Destination URL is invalid.');
        }
        if (isset($parts['query']) && preg_match('/(?:^|&)(?:token|key|secret|password|auth|authorization)=/i', $parts['query'])) {
            throw new InvalidArgumentException('Credentials are not allowed in destination URL query strings.');
        }

        $ips = $this->resolve($this->normalizeHost((string) $parts['host']));
        if ($ips === []) {
            throw new InvalidArgumentException('Destination host cannot be resolved.');
        }
        if (! $allowPrivate) {
            foreach ($ips as $ip) {
                if (! $this->isPublic($ip)) {
                    throw new InvalidArgumentException('Private or reserved destination addresses are blocked.');
                }
            }
        }
    }

    /**
     * Return a request-option array that pins the connection to a validated IP
     * so DNS cannot be re-resolved to an internal address between the check and
     * the request, and disable redirects so a 3xx cannot escape the guard.
     */
    public function pinnedOptions(string $url, bool $allowPrivate = false): array
    {
        $this->assertAllowed($url, $allowPrivate);
        $parts = parse_url($url);
        $host = $this->normalizeHost((string) $parts['host']);
        $ip = $this->resolve($host)[0] ?? $host;
        if (! $allowPrivate && ! $this->isPublic($ip)) {
            throw new InvalidArgumentException('Destination DNS changed to a private or reserved address.');
        }
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80));
        // CURLOPT_RESOLVE expects the bracket-free host and, for IPv6, a bracketed address.
        $pinned = str_contains($ip, ':') ? "[$ip]" : $ip;

        return defined('CURLOPT_RESOLVE') ? ['allow_redirects' => false, 'curl' => [constant('CURLOPT_RESOLVE') => ["{$parts['host']}:$port:$pinned"]]] : ['allow_redirects' => false];
    }

    /**
     * Resolve a host to every IPv4 and IPv6 address it points at. A literal IP
     * is returned as-is.
     *
     * @return array<int, string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = gethostbynamel($host) ?: [];
        // gethostbynamel() is IPv4-only; AAAA records must be resolved separately
        // or a host that publishes only a private IPv6 address would bypass the guard.
        set_error_handler(static fn () => true);
        try {
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (! empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        } finally {
            restore_error_handler();
        }

        return array_values(array_unique($ips));
    }

    /**
     * Public = not private, not reserved, in either address family. PHP's
     * FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE cover the IPv4 ranges and the
     * documented IPv6 reserved blocks; a few IPv6 ranges (ULA fc00::/7,
     * link-local fe80::/10, loopback ::1, and IPv4-mapped addresses) need an
     * explicit check.
     */
    private function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $binary = @inet_pton($ip);
            if ($binary === false) {
                return false;
            }
            // An IPv4-mapped address (::ffff:a.b.c.d) must be judged on its IPv4 value.
            if (str_starts_with($ip, '::ffff:') && ($mapped = filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))) {
                return $this->isPublic($mapped);
            }
            $first = ord($binary[0]);
            if ($ip === '::1' || $ip === '::') {
                return false;
            }
            if (($first & 0xFE) === 0xFC) { // fc00::/7 unique local
                return false;
            }
            if ($first === 0xFE && (ord($binary[1]) & 0xC0) === 0x80) { // fe80::/10 link-local
                return false;
            }
        }

        return true;
    }

    private function normalizeHost(string $host): string
    {
        // parse_url() keeps the brackets around an IPv6 literal host.
        return trim($host, '[]');
    }
}
