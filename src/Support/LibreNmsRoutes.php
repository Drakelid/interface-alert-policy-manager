<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Support;

/**
 * In-app links to LibreNMS's own pages.
 *
 * LibreNMS has no named route for a port; its port page is served by the
 * catch-all `device/{device}/{tab?}/{vars?}` route named `device`. Building
 * that in one place keeps views from hard-coding the shape, and keeps it
 * consistent with the absolute `port_url` that TemplateContextBuilder puts
 * into notification messages.
 */
class LibreNmsRoutes
{
    public static function device(int|string $deviceId): string
    {
        return route('device', ['device' => $deviceId]);
    }

    public static function port(int|string $deviceId, int|string $portId): string
    {
        return route('device', ['device' => $deviceId, 'tab' => 'port', 'vars' => $portId]);
    }
}
