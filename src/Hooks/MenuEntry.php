<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Hooks;

use Illuminate\Contracts\Auth\Authenticatable;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

class MenuEntry implements MenuEntryHook
{
    /**
     * Type-hint the Authenticatable contract, not App\Models\User: LibreNMS
     * resolves this argument from the container, and a concrete User hint would
     * be injected as a new empty model instead of the logged-in user, making the
     * gate check fail and the menu entry silently disappear.
     */
    public function authorize(Authenticatable $user, array $settings = []): bool
    {
        return $user->can('view iapm');
    }

    public function handle(string $pluginName): array
    {
        return ['iapm::menu', []];
    }
}
