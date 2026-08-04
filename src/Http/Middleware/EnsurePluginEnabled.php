<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\IapmServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes are registered unconditionally so that migrations, route caching and
 * the test suite do not depend on boot-time database state. A disabled plugin
 * must still expose nothing, so enablement is checked per request here.
 */
class EnsurePluginEnabled
{
    public function __construct(private readonly PluginManagerInterface $plugins) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->plugins->pluginEnabled(IapmServiceProvider::PLUGIN_NAME), 404);

        return $next($request);
    }
}
