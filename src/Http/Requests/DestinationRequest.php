<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage iapm destinations') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('destination')?->id;
        // An SMS gateway authenticates with HTTP Basic, so a password is required
        // on create. A generic webhook may use Basic auth, a bearer token, or none.
        $passwordRequired = ! $id && $this->input('type') === 'sms_gateway';

        return ['name' => ['required', 'string', 'max:255', Rule::unique('iapm_destinations')->ignore($id)], 'type' => ['required', Rule::in(['sms_gateway', 'generic_webhook'])], 'enabled' => ['boolean'], 'url' => ['required', 'url:http,https', 'max:2048'], 'username' => ['nullable', 'string', 'max:255'], 'password' => [$passwordRequired ? 'required' : 'nullable', 'string', 'max:4096'], 'bearer_token' => ['nullable', 'string', 'max:4096'], 'default_receiver' => ['nullable', 'string', 'max:128'], 'mode' => ['required', Rule::in(['json', 'form'])], 'connect_timeout' => ['required', 'integer', 'between:1,60'], 'timeout' => ['required', 'integer', 'between:1,300'], 'retry_count' => ['required', 'integer', 'between:0,10'], 'retry_delay_ms' => ['required', 'integer', 'between:0,60000'], 'verify_tls' => ['boolean'], 'allow_private_networks' => ['boolean'], 'headers_json' => ['nullable', 'json']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['enabled' => $this->boolean('enabled'), 'verify_tls' => $this->boolean('verify_tls'), 'allow_private_networks' => $this->boolean('allow_private_networks')]);
    }

    /**
     * The dispatcher runs `1 + retry_count` sequential HTTP attempts inside one
     * queue job, so the worst-case in-job budget must stay below the worker
     * timeout. Individually valid fields can otherwise combine into a job the
     * worker kills mid-flight, leaving the outbox row to be stale-reclaimed and
     * retried forever — duplicate gateway calls that only a compliant
     * Idempotency-Key suppresses.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['timeout', 'retry_count', 'retry_delay_ms'])) {
                return; // field-level rules already reported the problem
            }
            $ceiling = $this->deliveryBudgetCeilingSeconds();
            $worstCase = $this->worstCaseDeliverySeconds();
            if ($worstCase > $ceiling) {
                $validator->errors()->add('timeout', "Worst-case delivery takes {$worstCase}s (".(1 + (int) $this->input('retry_count'))." attempts x {$this->input('timeout')}s, plus retry delays) but must stay under {$ceiling}s so the queue worker cannot kill the job mid-delivery. Reduce the request timeout, the retry count, or the retry delay.");
            }
        });
    }

    /** Worst-case seconds one outbox row can occupy a worker. */
    private function worstCaseDeliverySeconds(): int
    {
        $attempts = 1 + max(0, min(10, (int) $this->input('retry_count')));
        $delaySeconds = ($attempts - 1) * ((int) $this->input('retry_delay_ms') / 1000);

        return (int) ceil($attempts * (int) $this->input('timeout') + $delaySeconds);
    }

    /**
     * Leave headroom below the worker timeout for connection setup, the claim
     * transaction, and finalization, which also run inside the job.
     */
    private function deliveryBudgetCeilingSeconds(): int
    {
        $workerTimeout = max(1, (int) config('iapm.queue.timeout', 60));

        return max(1, (int) floor($workerTimeout * (float) config('iapm.queue.delivery_budget_ratio', 0.8)));
    }
}
