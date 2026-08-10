<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\StateNormalizer;

class IngestAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * LibreNMS alert templates may emit identifiers as JSON strings or numbers
     * depending on how the template was written. Normalize the shapes we can
     * convert without ambiguity and let validation reject everything else.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_scalar($uid = $this->input('alert_uid'))) {
            $normalized['alert_uid'] = (string) $uid;
        }

        foreach (['alert_id', 'rule_id', 'device_id', 'state'] as $key) {
            $value = $this->input($key);
            if (is_string($value) && ctype_digit(trim($value))) {
                $normalized[$key] = (int) trim($value);
            }
        }

        if (is_array($faults = $this->input('faults'))) {
            $normalized['faults'] = array_values(array_map(static function ($fault) {
                if (is_array($fault) && isset($fault['port_id']) && is_string($fault['port_id']) && ctype_digit(trim($fault['port_id']))) {
                    $fault['port_id'] = (int) trim($fault['port_id']);
                }

                return $fault;
            }, $faults));
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'alert_uid' => ['nullable', 'string', 'max:128'],
            'alert_id' => ['nullable', 'integer', 'min:1'],
            'rule_id' => ['nullable', 'integer', 'min:1'],
            'device_id' => ['required', 'integer', 'min:1'],
            'state' => ['required', $this->stateRule()],
            'severity' => ['nullable', 'in:info,warning,critical'],
            'timestamp' => ['nullable', 'date'],
            'faults' => ['present', 'array', 'max:10000'],
            'faults.*' => ['required', 'array'],
            'faults.*.port_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Reject states the normalizer cannot map, so an unsupported state is a
     * structured 422 rather than an unhandled exception.
     */
    private function stateRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_int($value) && ! is_string($value)) {
                $fail('The alert state must be an integer or a string.');

                return;
            }

            try {
                app(StateNormalizer::class)->normalize($value);
            } catch (\Throwable) {
                $fail('The alert state is not a supported LibreNMS alert state.');
            }
        };
    }

    protected function failedValidation(Validator $validator): void
    {
        $logKey = 'iapm:invalid-payload-log:'.hash('sha256', (string) $this->ip());
        if (! RateLimiter::tooManyAttempts($logKey, 1)) {
            RateLimiter::hit($logKey, 60);
            Log::channel('iapm')->warning('Ingestion payload validation failed.', ['ip' => $this->ip(), 'fields' => array_keys($validator->errors()->toArray())]);
        }
        throw new HttpResponseException(response()->json(['error' => ['code' => 'validation_failed', 'message' => 'The alert payload is invalid.', 'fields' => $validator->errors()]], 422));
    }
}
