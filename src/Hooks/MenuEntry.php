<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Hooks;

use App\Models\User;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

class MenuEntry implements MenuEntryHook
{
    /**
     * LibreNMS resolves this argument from the container via app()->call(), which
     * hands us a fresh empty User rather than the logged-in one — so the injected
     * $user cannot be used for a permission check. Read the real user from the
     * auth guard instead. The parameter type matches the hook base class so the
     * container has something instantiable to inject.
     */
    public function authorize(User $user): bool
    {
        return (bool) auth()->user()?->can('view iapm');
    }

    public function handle(string $pluginName): array
    {
        return ['iapm::menu', []];
    }
}
