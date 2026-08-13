<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Support;

/**
 * In-app links to LibreNMS's own pages.
 *
 * LibreNMS has no named route for a port. Its own `Url::portUrl()` helper uses
 * the legacy variable path below, which the fallback device controller parses
 * into device, tab and port values. A superficially REST-like
 * `/device/{id}/port/{id}` path matches the fallback route but leaves the port
 * variable unset and ultimately produces a 404.
 */
class LibreNmsRoutes
{
    public static function device(int|string $deviceId): string
    {
        return route('device', ['device' => $deviceId]);
    }

    public static function port(int|string $deviceId, int|string $portId): string
    {
        // Laravel's URL generator trims a trailing slash from the supplied path.
        // LibreNMS documents and emits this legacy variable route with one.
        return rtrim(url(self::portPath($deviceId, $portId)), '/').'/';
    }

    public static function absolutePort(string $baseUrl, int|string $deviceId, int|string $portId): string
    {
        return rtrim($baseUrl, '/').'/'.self::portPath($deviceId, $portId);
    }

    private static function portPath(int|string $deviceId, int|string $portId): string
    {
        return 'device/device='.rawurlencode((string) $deviceId)
            .'/tab=port/port='.rawurlencode((string) $portId).'/';
    }
}
