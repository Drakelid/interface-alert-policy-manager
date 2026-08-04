<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Hooks;

use App\Models\User;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

class Settings implements SettingsHook
{
    /**
     * The injected $user is a container-resolved empty model, not the logged-in
     * user, so read the real user from the auth guard. The plugin-admin settings
     * page that renders this hook is itself gated by the plugin.admin ability.
     */
    public function authorize(User $user): bool
    {
        return (bool) auth()->user()?->can('view iapm');
    }

    public function handle(string $pluginName, array $settings): array
    {
        return ['content_view' => 'iapm::settings', 'settings' => $settings];
    }
}
