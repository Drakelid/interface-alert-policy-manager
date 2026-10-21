<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class UrlGuard
{
    /**
     * Whether this process has already reported that connection pinning is
     * unavailable. The warning describes a static property of the runtime, so
     * repeating it once per delivery would flood the log during a storm.
     */
    private static bool $reportedUnpinned = false;

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

        if (! defined('CURLOPT_RESOLVE')) {
            // Without ext-curl, Guzzle falls back to the PHP stream handler and the
            // connection cannot be pinned to the address just validated. Redirects
            // stay disabled and the pre-flight check still runs, but a hostile DNS
            // server can now answer differently for the request itself. Deliveries
            // continue — refusing them would break otherwise working installations
            // on a curl-less runtime — but the weakened guarantee is recorded once
            // rather than degrading silently.
            $this->reportMissingPinning();

            return ['allow_redirects' => false];
        }

        return ['allow_redirects' => false, 'curl' => [constant('CURLOPT_RESOLVE') => ["{$parts['host']}:$port:$pinned"]]];
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

    /** Globally reachable unicast, including explicit IANA special-use rules. */
    private function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }
        // Mapped addresses have many equivalent spellings (uppercase,
        // expanded zeros and hexadecimal tails). Judge their binary IPv4
        // payload before PHP's version-dependent reserved-range classification.
        if (strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $mapped = inet_ntop(substr($binary, 12));

            return $mapped !== false && $this->isPublic($mapped);
        }
        if (strlen($binary) === 4) {
            // PHP's range flags differ across supported patch versions and do
            // not consistently include special-use exceptions. Match IANA's
            // non-global ranges explicitly, plus multicast.
            if (in_array(inet_ntop($binary), ['192.0.0.9', '192.0.0.10'], true)) {
                return true;
            }
            foreach ([['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$network, $bits]) {
                if ($this->inSubnet($binary, $network, $bits)) {
                    return false;
                }
            }

            return true;
        }
        // The well-known NAT64 prefix encodes an IPv4 destination. Judge it as
        // IPv4 so a translator cannot turn an apparently public IPv6 URL into
        // a request to loopback, metadata or a private address.
        if ($this->inSubnet($binary, '64:ff9b::', 96)) {
            $translated = inet_ntop(substr($binary, 12));

            return $translated !== false && $this->isPublic($translated);
        }
        // Restrict native IPv6 to global unicast. This also excludes local
        // translation prefixes, multicast, site-local and unallocated space.
        if (! $this->inSubnet($binary, '2000::', 3)
            || $this->inSubnet($binary, '2001:db8::', 32)
            || $this->inSubnet($binary, '2002::', 16)
            || $this->inSubnet($binary, '3fff::', 20)) {
            return false;
        }
        // Most IETF protocol space is not globally reachable. Keep its
        // explicit global exceptions (IANA registry, updated 2025-10-09).
        if ($this->inSubnet($binary, '2001::', 23)) {
            foreach ([['2001:1::1', 128], ['2001:1::2', 128], ['2001:1::3', 128], ['2001:3::', 32], ['2001:4:112::', 48], ['2001:20::', 28], ['2001:30::', 28]] as [$network, $bits]) {
                if ($this->inSubnet($binary, $network, $bits)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private function inSubnet(string $binary, string $network, int $bits): bool
    {
        $prefix = inet_pton($network);
        if ($prefix === false || strlen($binary) !== strlen($prefix)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remaining = $bits % 8;

        return substr($binary, 0, $bytes) === substr($prefix, 0, $bytes)
            && ($remaining === 0 || ((ord($binary[$bytes]) ^ ord($prefix[$bytes])) & (0xFF << (8 - $remaining))) === 0);
    }

    private function reportMissingPinning(): void
    {
        if (self::$reportedUnpinned) {
            return;
        }
        self::$reportedUnpinned = true;
        try {
            Log::channel('iapm')->warning('Outbound connections cannot be pinned to the validated IP: ext-curl is unavailable, so CURLOPT_RESOLVE is not defined. Redirects remain disabled and destination addresses are still checked before each request, but DNS rebinding between the check and the request is no longer prevented. Install ext-curl on the LibreNMS host to restore pinning.');
        } catch (\Throwable) {
            // Never let a logging failure block a notification.
        }
    }

    private function normalizeHost(string $host): string
    {
        // parse_url() keeps the brackets around an IPv6 literal host.
        return trim($host, '[]');
    }
}
