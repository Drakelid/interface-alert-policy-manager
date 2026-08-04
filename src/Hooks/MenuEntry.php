<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Hooks;

use App\Models\User;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

class MenuEntry implements MenuEntryHook
{
    public function authorize(User $user): bool
    {
        return $user->can('view iapm') || $user->hasRole('admin');
    }

    public function handle(string $pluginName): array
    {
        return ['iapm::menu', []];
    }
}
