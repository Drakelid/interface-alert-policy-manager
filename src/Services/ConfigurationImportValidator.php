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
            $policies = collect($document['policies'] ?? []);
            $assignments = $policies->flatMap(fn (array $policy) => $policy['assignments'] ?? []);
            $existingPolicyNames = $this->existing('iapm_policies', 'name', $policies->pluck('name')->filter()->all());
            $newAssignments = $policies->reject(fn (array $policy) => $existingPolicyNames->contains($policy['name'] ?? null))->flatMap(fn (array $policy) => $policy['assignments'] ?? []);
            $regexLimit = max(1, (int) config('iapm.resolver.max_regex_assignments', 5000));
            foreach (['ifalias_regex', 'ifname_regex'] as $regexType) {
                $existingRegex = DB::table('iapm_assignments')->join('iapm_policies', 'iapm_policies.id', '=', 'iapm_assignments.policy_id')->where('iapm_assignments.assignment_type', $regexType)->where('iapm_assignments.enabled', true)->where('iapm_policies.enabled', true)->count();
                if ($existingRegex + $newAssignments->where('assignment_type', $regexType)->where('enabled', true)->count() > $regexLimit) {
                    $validator->errors()->add('policies', "The import exceeds the configured {$regexType} safety limit of {$regexLimit}.");
                }
            }
            $scheduleNames = $policies->pluck('schedule')->filter()->unique()->values()->all();
            $destinationNames = $policies->flatMap(fn (array $policy) => collect($policy['actions'] ?? [])->pluck('destination'))->filter()->unique()->values()->all();
            $groupIds = $assignments->flatMap(fn (array $assignment) => $assignment['device_group_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
            $knownSchedules = $this->existing('iapm_schedules', 'name', $scheduleNames)->merge($importedSchedules)->unique();
            $destinations = $this->existing('iapm_destinations', 'name', $destinationNames);
            $knownGroups = $this->existing('device_groups', 'id', $groupIds)->map(fn ($id) => (int) $id);
            // Resolve only identifiers referenced by this bounded document. Loading
            // every port id made import validation O(fleet size) and could exhaust
            // memory on a 500k-interface LibreNMS instance.
            $knownReferences = [
                'port' => $this->existingReferences($assignments, 'port', 'ports', 'port_id'),
                'port_group' => $this->existingReferences($assignments, 'port_group', 'port_groups', 'id'),
                'device' => $this->existingReferences($assignments, 'device', 'devices', 'device_id'),
                'location' => $this->existingReferences($assignments, 'location', 'locations', 'id'),
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

    private function existingReferences($assignments, string $type, string $table, string $column)
    {
        $values = $assignments->where('assignment_type', $type)->pluck('assignment_reference')->filter()->map(fn ($value) => (string) $value)->unique()->values()->all();

        return $this->existing($table, $column, $values)->map(fn ($value) => (string) $value);
    }

    private function existing(string $table, string $column, array $values)
    {
        return collect($values)->chunk(1000)->flatMap(
            fn ($chunk) => DB::table($table)->whereIn($column, $chunk->all())->pluck($column)
        )->unique()->values();
    }
}
