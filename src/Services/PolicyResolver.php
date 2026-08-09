<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\DTO\InterfaceContext;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\DTO\PolicyResolution;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\AssignmentType;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;

class PolicyResolver
{
    /** @var array<string,mixed>|null */
    private ?array $index = null;

    private Policy|false|null $configuredDefault = null;

    public function __construct(private readonly SettingStore $settings) {}

    public function resolve(InterfaceContext $context, bool $writeCache = true): PolicyResolution
    {
        $index = $this->index ??= $this->buildIndex();
        $candidateIds = collect()
            ->merge($index['port'][$context->portId] ?? [])
            ->merge($index['device'][$context->deviceId] ?? [])
            ->merge($context->locationId === null ? [] : ($index['location'][$context->locationId] ?? []))
            ->merge($index['interface_type'][$context->ifType] ?? [])
            ->merge(collect($context->portGroupIds)->flatMap(fn ($id) => $index['port_group'][$id] ?? []))
            ->merge(collect($context->deviceGroupIds)->flatMap(fn ($id) => $index['device_group'][$id] ?? []))
            ->merge($index['device_group_complex'])
            ->merge($index['ifalias_regex'])
            ->merge($index['ifname_regex'])
            ->merge($index['default'])
            ->unique();
        $matches = $candidateIds->map(fn ($id) => $index['all'][$id] ?? null)->filter(fn ($a) => $a instanceof Assignment)->filter(fn (Assignment $a) => $this->matches($a, $context))->sort(function (Assignment $a, Assignment $b): int {
            return [$b->assignment_type->specificity(), $b->priority, $b->policy->priority, $b->updated_at?->timestamp ?? 0] <=> [$a->assignment_type->specificity(), $a->priority, $a->policy->priority, $a->updated_at?->timestamp ?? 0];
        })->values();
        /** @var Assignment|null $winner */
        $winner = $matches->first();
        if (! $winner && $this->configuredDefault === null) {
            $id = $this->settings->get('default_policy_id');
            $this->configuredDefault = $id ? (Policy::where('enabled', true)->find($id) ?: false) : false;
        }
        $resolution = new PolicyResolution($winner?->policy ?? ($this->configuredDefault ?: null), $winner, $matches->all());
        // The policy cache is a materialised view for the UI matrix. Writing it on
        // every resolve means one upsert per fault on the ingestion hot path — heavy
        // during a storm. Callers on that path pass writeCache=false; the per-minute
        // reconcile (and the rebuild command) keep the cache current for alerting
        // interfaces without adding write amplification to ingestion.
        if ($writeCache) {
            try {
                DB::table('iapm_interface_policy_cache')->updateOrInsert(['port_id' => $context->portId], ['policy_id' => $resolution->policy?->id, 'assignment_id' => $winner?->id, 'assignment_source' => $winner?->assignment_type->value ?? ($resolution->policy ? 'configured_default' : null), 'candidate_assignment_ids' => json_encode($matches->pluck('id')->all()), 'resolved_at' => now()]);
            } catch (\Throwable) { /* installation may not be migrated yet */
            }
        }

        return $resolution;
    }

    /** Build once per resolver instance; exact match types never scan unrelated rows. */
    private function buildIndex(): array
    {
        $assignments = Assignment::query()->with(['policy.schedule', 'deviceGroups'])->where('enabled', true)->whereHas('policy', fn ($query) => $query->where('enabled', true))->get();
        $index = ['all' => [], 'port' => [], 'device' => [], 'location' => [], 'port_group' => [], 'device_group' => [], 'interface_type' => [], 'default' => [], 'ifalias_regex' => [], 'ifname_regex' => [], 'device_group_complex' => []];
        $regexLimit = max(1, (int) config('iapm.resolver.max_regex_assignments', 5000));

        foreach ($assignments as $assignment) {
            $index['all'][$assignment->id] = $assignment;
            $type = $assignment->assignment_type;
            if ($type === AssignmentType::IfAliasRegex || $type === AssignmentType::IfNameRegex) {
                $bucket = $type === AssignmentType::IfAliasRegex ? 'ifalias_regex' : 'ifname_regex';
                if (count($index[$bucket]) < $regexLimit) {
                    $index[$bucket][] = $assignment->id;
                }

                continue;
            }
            if ($type === AssignmentType::DeviceGroup) {
                if ($assignment->match_mode === 'any') {
                    foreach ($assignment->deviceGroups as $group) {
                        $index['device_group'][(int) $group->device_group_id][] = $assignment->id;
                    }
                } else {
                    $index['device_group_complex'][] = $assignment->id;
                }

                continue;
            }
            if ($type === AssignmentType::Default) {
                $index['default'][] = $assignment->id;

                continue;
            }
            $bucket = $type->value;
            $key = $type === AssignmentType::InterfaceType ? (string) $assignment->assignment_reference : (int) $assignment->assignment_reference;
            $index[$bucket][$key][] = $assignment->id;
        }

        return $index;
    }

    private function matches(Assignment $a, InterfaceContext $c): bool
    {
        $type = $a->assignment_type;
        if (! $type instanceof AssignmentType) {
            return false;
        }

        return match ($type) {
            AssignmentType::Port => (int) $a->assignment_reference === $c->portId,
            AssignmentType::Device => (int) $a->assignment_reference === $c->deviceId,
            AssignmentType::Location => (int) $a->assignment_reference === $c->locationId,
            AssignmentType::PortGroup => in_array((int) $a->assignment_reference, $c->portGroupIds, true),
            AssignmentType::InterfaceType => $a->assignment_reference === $c->ifType,
            AssignmentType::IfAliasRegex => $this->regex($a->match_expression, $c->ifAlias),
            AssignmentType::IfNameRegex => $this->regex($a->match_expression, $c->ifName),
            AssignmentType::DeviceGroup => $this->groups($a, $c->deviceGroupIds),
            AssignmentType::Default => true,
        };
    }

    private function regex(?string $pattern, string $subject): bool
    {
        if (! $pattern || strlen($pattern) > 1000) {
            return false;
        } set_error_handler(static fn () => true);
        try {
            return preg_match($pattern, $subject, $m, PREG_UNMATCHED_AS_NULL) === 1;
        } finally {
            restore_error_handler();
        }
    }

    private function groups(Assignment $a, array $actual): bool
    {
        $selected = $a->deviceGroups->pluck('device_group_id')->map(fn ($id) => (int) $id)->all();
        if ($selected === []) {
            return false;
        } /* no groups chosen => match nothing (matches the preview), never a catch-all */

        return match ($a->match_mode) {
            'all' => array_diff($selected, $actual) === [], 'exclude' => array_intersect($selected, $actual) === [], default => array_intersect($selected, $actual) !== []
        };
    }
}
