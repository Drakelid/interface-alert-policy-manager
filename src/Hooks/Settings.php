<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Hooks;

use Illuminate\Contracts\Auth\Authenticatable;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

class Settings implements SettingsHook
{
    /**
     * Type-hint the Authenticatable contract, not App\Models\User: LibreNMS
     * resolves this from the container and a concrete User hint would be a new
     * empty model, failing the gate and rendering the "missing view" fallback.
     */
    public function authorize(Authenticatable $user): bool
    {
        return $user->can('manage iapm settings');
    }

    public function handle(string $pluginName, array $settings): array
    {
        return ['content_view' => 'iapm::settings', 'settings' => $settings];
    }
}
