<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage iapm assignments') ?? false;
    }

    public function rules(): array
    {
        return ['policy_id' => ['required', 'exists:iapm_policies,id'], 'assignment_type' => ['required', Rule::in(['port', 'device', 'device_group', 'port_group', 'location', 'ifalias_regex', 'ifname_regex', 'interface_type', 'default'])], 'assignment_reference' => ['nullable', 'string', 'max:255'], 'match_expression' => ['nullable', 'string', 'max:1000', function (string $a, mixed $v, Closure $fail) {
            if (in_array($this->input('assignment_type'), ['ifalias_regex', 'ifname_regex'], true)) {
                set_error_handler(static fn () => true);
                try {
                    $ok = @preg_match((string) $v, '') !== false;
                } finally {
                    restore_error_handler();
                }if (! $ok) {
                    $fail('The regular expression is invalid.');
                }
            }
        }], 'match_mode' => ['required', Rule::in(['any', 'all', 'exclude'])], 'priority' => ['required', 'integer', 'between:-100000,100000'], 'enabled' => ['boolean'], 'device_group_ids' => ['array', 'max:1000'], 'device_group_ids.*' => ['integer', 'exists:device_groups,id'], 'receivers' => ['array', 'max:100'], 'receivers.*' => ['string', 'max:128']];
    }

    protected function prepareForValidation(): void
    {
        // Device groups arrive either from the multi-select (device_group_ids[]) or,
        // as a fallback, from a comma/space/newline separated text field.
        $groups = $this->input('device_group_ids');
        if (! is_array($groups)) {
            $groups = preg_split('/[\s,]+/', trim((string) $this->input('device_group_ids_text', '')), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $receivers = $this->input('receivers');
        if (! is_array($receivers)) {
            $receivers = preg_split('/[\r\n,]+/', trim((string) $this->input('receivers_text', '')), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $this->merge(['enabled' => $this->boolean('enabled'), 'device_group_ids' => array_values($groups), 'receivers' => array_values($receivers)]);
    }
}
