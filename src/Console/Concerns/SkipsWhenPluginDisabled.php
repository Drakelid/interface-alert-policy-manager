<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\Concerns;

use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\IapmServiceProvider;

/**
 * Scheduled IAPM commands are registered with the scheduler unconditionally,
 * so each one must decline to act while the plugin is disabled.
 */
trait SkipsWhenPluginDisabled
{
    protected function pluginDisabled(): bool
    {
        try {
            $enabled = app(PluginManagerInterface::class)->pluginEnabled(IapmServiceProvider::PLUGIN_NAME);
        } catch (\Throwable) {
            $enabled = false;
        }

        if (! $enabled) {
            $this->info('IAPM is disabled; nothing to do.');
        }

        return ! $enabled;
    }
}
