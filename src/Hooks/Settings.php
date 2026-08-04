<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Hooks;

use App\Models\User;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

class Settings implements SettingsHook
{
    public function authorize(User $user): bool
    {
        return $user->can('manage iapm settings') || $user->hasRole('admin');
    }

    public function handle(string $pluginName, array $settings): array
    {
        return ['content_view' => 'iapm::settings', 'settings' => $settings];
    }
}
