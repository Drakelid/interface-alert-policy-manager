<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Rules;

use App\Models\Port;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Rejects stale LibreNMS port rows whose parent device has been removed. */
class PortHasDevice implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || ! Port::query()->whereKey((int) $value)->whereHas('device')->exists()) {
            $fail('The selected interface no longer belongs to an existing LibreNMS device.');
        }
    }
}
