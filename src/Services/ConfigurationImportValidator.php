<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConfigurationImportValidator
{
    public const MAX_RECORDS = 20000;

    public function __construct(private readonly ScheduleEvaluator $schedules) {}

    public function validate(array $document): array
    {
        $validator = Validator::make($document, [
            'version' => ['required', 'integer', Rule::in([1])],
            'exported_at' => ['nullable', 'date'],
            'schedules' => ['present', 'array', 'max:200'],
            'schedules.*' => ['required', 'array'],
            'schedules.*.name' => ['required', 'string', 'max:255', 'distinct'],
            'schedules.*.timezone' => ['required', 'string', 'max:64', 'timezone:all'],
            'schedules.*.enabled' => ['required', 'boolean'],
            'schedules.*.schedule_json' => ['required', 'array'],
            'policies' => ['present', 'array', 'max:1000'],
            'policies.*' => ['required', 'array'],
            'policies.*.name' => ['required', 'string', 'max:255', 'distinct'],
            'policies.*.description' => ['nullable', 'string', 'max:5000'],
            'policies.*.enabled' => ['required', 'boolean'],
            'policies.*.priority' => ['required', 'integer', 'between:-100000,100000'],
            'policies.*.severity' => ['required', Rule::in(['info', 'warning', 'critical'])],
            'policies.*.default_receiver' => ['nullable', 'string', 'max:128'],
            'policies.*.notifications_enabled' => ['required', 'boolean'],
            'policies.*.trigger_after_seconds' => ['required', 'integer', 'between:0,2592000'],
            'policies.*.failed_poll_count' => ['required', 'integer', 'between:1,1000'],
            'policies.*.recovery_after_seconds' => ['required', 'integer', 'between:0,2592000'],
            'policies.*.repeat_seconds' => ['nullable', 'integer', 'between:60,2592000'],
            'policies.*.maximum_repeats' => ['nullable', 'integer', 'between:0,10000'],
            'policies.*.notify_recovery' => ['required', 'boolean'],
            'policies.*.suppress_device_down' => ['required', 'boolean'],
            'policies.*.suppress_admin_down' => ['required', 'boolean'],
            'policies.*.suppress_ignored_port' => ['required', 'boolean'],
            'policies.*.suppress_disabled_port' => ['required', 'boolean'],
            'policies.*.suppress_deleted_port' => ['required', 'boolean'],
            'policies.*.suppress_maintenance' => ['required', 'boolean'],
            'policies.*.suppress_parent_down' => ['required', 'boolean'],
            'policies.*.suppress_uplink_down' => ['required', 'boolean'],
            'policies.*.flap_threshold' => ['nullable', 'integer', 'between:2,1000'],
            'policies.*.flap_window_seconds' => ['nullable', 'integer', 'between:30,86400'],
            'policies.*.flap_settle_seconds' => ['nullable', 'integer', 'between:0,86400'],
            'policies.*.schedule' => ['nullable', 'string', 'max:255'],
            'policies.*.actions' => ['present', 'array', 'max:100'],
            'policies.*.actions.*.destination' => ['required', 'string', 'max:255'],
            'policies.*.actions.*.phase' => ['required', Rule::in(['trigger', 'escalation', 'reminder', 'recovery', 'acknowledged'])],
            'policies.*.actions.*.delay_seconds' => ['required', 'integer', 'between:0,2592000'],
            'policies.*.actions.*.repeat_seconds' => ['nullable', 'integer', 'between:60,2592000'],
            'policies.*.actions.*.maximum_sends' => ['nullable', 'integer', 'between:1,10000'],
            'policies.*.actions.*.receivers_json' => ['nullable', 'array', 'max:100'],
            'policies.*.actions.*.receivers_json.*' => ['string', 'max:128'],
            'policies.*.actions.*.message_template' => ['nullable', 'string', 'max:10000'],
            'policies.*.actions.*.enabled' => ['required', 'boolean'],
            'policies.*.actions.*.sort_order' => ['required', 'integer', 'between:0,10000'],
            'policies.*.assignments' => ['present', 'array', 'max:5000'],
            'policies.*.assignments.*.assignment_type' => ['required', Rule::in(['port', 'port_group', 'device', 'device_group', 'location', 'ifalias_regex', 'ifname_regex', 'interface_type', 'default'])],
            'policies.*.assignments.*.assignment_reference' => ['nullable', 'string', 'max:255'],
            'policies.*.assignments.*.match_expression' => ['nullable', 'string', 'max:1000'],
            'policies.*.assignments.*.match_mode' => ['required', Rule::in(['any', 'all', 'exclude'])],
            'policies.*.assignments.*.priority' => ['required', 'integer', 'between:-100000,100000'],
            'policies.*.assignments.*.enabled' => ['required', 'boolean'],
            'policies.*.assignments.*.metadata_json' => ['nullable', 'array'],
            'policies.*.assignments.*.metadata_json.receivers' => ['nullable', 'array', 'max:100'],
            'policies.*.assignments.*.metadata_json.receivers.*' => ['string', 'max:128'],
            'policies.*.assignments.*.device_group_ids' => ['nullable', 'array', 'max:1000'],
            'policies.*.assignments.*.device_group_ids.*' => ['integer'],
        ]);
        $validator->after(function ($validator) use ($document): void {
            $records = count($document['schedules'] ?? []) + count($document['policies'] ?? []);
            $importedSchedules = collect($document['schedules'] ?? [])->pluck('name');
            $knownSchedules = DB::table('iapm_schedules')->pluck('name')->merge($importedSchedules)->unique();
            $destinations = DB::table('iapm_destinations')->pluck('name');
            $knownGroups = DB::table('device_groups')->pluck('id')->map(fn ($id) => (int) $id);
            $knownReferences = [
                'port' => DB::table('ports')->pluck('port_id')->map(fn ($id) => (string) $id),
                'port_group' => DB::table('port_groups')->pluck('id')->map(fn ($id) => (string) $id),
                'device' => DB::table('devices')->pluck('device_id')->map(fn ($id) => (string) $id),
                'location' => DB::table('locations')->pluck('id')->map(fn ($id) => (string) $id),
            ];
            foreach (($document['schedules'] ?? []) as $index => $schedule) {
                try {
                    $this->schedules->validate((array) ($schedule['schedule_json'] ?? []));
                } catch (\InvalidArgumentException $exception) {
                    $validator->errors()->add("schedules.{$index}.schedule_json", $exception->getMessage());
                }
            }
            foreach (($document['policies'] ?? []) as $policyIndex => $policy) {
                $records += count($policy['actions'] ?? []) + count($policy['assignments'] ?? []);
                if (filled($policy['schedule'] ?? null) && ! $knownSchedules->contains($policy['schedule'])) {
                    $validator->errors()->add("policies.{$policyIndex}.schedule", 'The referenced schedule does not exist.');
                }
                foreach (($policy['actions'] ?? []) as $actionIndex => $action) {
                    if (! $destinations->contains($action['destination'] ?? null)) {
                        $validator->errors()->add("policies.{$policyIndex}.actions.{$actionIndex}.destination", 'The referenced destination does not exist.');
                    }
                }
                foreach (($policy['assignments'] ?? []) as $assignmentIndex => $assignment) {
                    $type = $assignment['assignment_type'] ?? null;
                    if (in_array($type, ['ifalias_regex', 'ifname_regex'], true) && ! $this->validRegex($assignment['match_expression'] ?? null)) {
                        $validator->errors()->add("policies.{$policyIndex}.assignments.{$assignmentIndex}.match_expression", 'The regular expression is invalid.');
                    }
                    if (isset($knownReferences[$type]) && ! $knownReferences[$type]->contains((string) ($assignment['assignment_reference'] ?? ''))) {
                        $validator->errors()->add("policies.{$policyIndex}.assignments.{$assignmentIndex}.assignment_reference", 'The referenced LibreNMS entity does not exist.');
                    }
                    if ($type === 'interface_type' && blank($assignment['assignment_reference'] ?? null)) {
                        $validator->errors()->add("policies.{$policyIndex}.assignments.{$assignmentIndex}.assignment_reference", 'An interface type is required.');
                    }
                    if ($type === 'device_group' && empty($assignment['device_group_ids'])) {
                        $validator->errors()->add("policies.{$policyIndex}.assignments.{$assignmentIndex}.device_group_ids", 'At least one device group is required.');
                    }
                    foreach (($assignment['device_group_ids'] ?? []) as $groupId) {
                        if (! $knownGroups->contains((int) $groupId)) {
                            $validator->errors()->add("policies.{$policyIndex}.assignments.{$assignmentIndex}.device_group_ids", "Device group {$groupId} does not exist.");
                        }
                    }
                }
            }
            if ($records > self::MAX_RECORDS) {
                $validator->errors()->add('policies', 'The import exceeds the 20,000 record safety limit.');
            }
        });
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $document;
    }

    private function validRegex(mixed $pattern): bool
    {
        if (! is_string($pattern) || $pattern === '' || strlen($pattern) > 1000) {
            return false;
        }
        set_error_handler(static fn () => true);
        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
