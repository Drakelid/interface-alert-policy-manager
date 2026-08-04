<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use InvalidArgumentException;

class UrlGuard
{
    public function assertAllowed(string $url, bool $allowPrivate = false): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) throw new InvalidArgumentException('Destination URL is invalid.');
        if (isset($parts['query']) && preg_match('/(?:^|&)(?:token|key|secret|password|auth|authorization)=/i', $parts['query'])) throw new InvalidArgumentException('Credentials are not allowed in destination URL query strings.');
        $ips = gethostbynamel($parts['host']) ?: (filter_var($parts['host'], FILTER_VALIDATE_IP) ? [$parts['host']] : []);
        if ($ips === []) throw new InvalidArgumentException('Destination host cannot be resolved.');
        if (! $allowPrivate) foreach ($ips as $ip) if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new InvalidArgumentException('Private or reserved destination addresses are blocked.');
    }

    public function pinnedOptions(string $url, bool $allowPrivate = false): array
    {
        $this->assertAllowed($url, $allowPrivate); $parts = parse_url($url); $host = (string) $parts['host']; $ip = (gethostbynamel($host) ?: [$host])[0];
        if (! $allowPrivate && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new InvalidArgumentException('Destination DNS changed to a private or reserved address.');
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80));
        return defined('CURLOPT_RESOLVE') ? ['allow_redirects' => false, 'curl' => [constant('CURLOPT_RESOLVE') => ["$host:$port:$ip"]]] : ['allow_redirects' => false];
    }
}
