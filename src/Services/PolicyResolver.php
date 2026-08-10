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

    /** @var array<string, string|false> */
    private array $safeRegex = [];

    private ?int $regexSubjectBytes = null;

    public function __construct(private readonly SettingStore $settings) {}

    public function resolve(InterfaceContext $context, bool $writeCache = true): PolicyResolution
    {
        $index = $this->index ??= $this->buildIndex();
        $portGroupIds = [];
        foreach ($context->portGroupIds as $id) {
            array_push($portGroupIds, ...($index['port_group'][$id] ?? []));
        }
        $deviceGroupIds = [];
        foreach ($context->deviceGroupIds as $id) {
            array_push($deviceGroupIds, ...($index['device_group'][$id] ?? []));
        }

        // Assignment precedence is immutable for this resolver instance. Rank the
        // topology once in buildIndex(). Exact-match buckets are already matches
        // and already ranked, so only regex and complex group assignments need
        // per-interface evaluation. This avoids both model sorting and redundant
        // matcher calls during cache rebuilds and interface storms.
        $matches = [];
        $append = function (array $ids, bool $evaluate = false) use (&$matches, $index, $context): void {
            foreach ($ids as $id) {
                $assignment = $index['all'][$id] ?? null;
                if ($assignment instanceof Assignment && (! $evaluate || $this->matches($assignment, $context))) {
                    $matches[] = $assignment;
                }
            }
        };
        $appendRegex = function (array $ids, string $subject) use (&$matches, $index): void {
            foreach ($ids as $id) {
                $pattern = $index['regex_patterns'][$id] ?? null;
                if (is_string($pattern) && $this->regex($pattern, $subject)) {
                    $matches[] = $index['all'][$id];
                }
            }
        };
        $append($index['port'][$context->portId] ?? []);
        $append($this->rankedUnique($portGroupIds, $index['rank']));
        $append($index['device'][$context->deviceId] ?? []);
        $matchedComplexGroups = array_values(array_filter($index['device_group_complex'], function ($id) use ($index, $context): bool {
            $assignment = $index['all'][$id] ?? null;

            return $assignment instanceof Assignment && $this->matches($assignment, $context);
        }));
        $append($this->rankedUnique(array_merge($deviceGroupIds, $matchedComplexGroups), $index['rank']));
        $append($context->locationId === null ? [] : ($index['location'][$context->locationId] ?? []));
        $appendRegex($index['ifalias_regex'], $context->ifAlias);
        $appendRegex($index['ifname_regex'], $context->ifName);
        $append($index['interface_type'][$context->ifType] ?? []);
        $append($index['default']);
        /** @var Assignment|null $winner */
        $winner = $matches[0] ?? null;
        if (! $winner && $this->configuredDefault === null) {
            $id = $this->settings->get('default_policy_id');
            $this->configuredDefault = $id ? (Policy::where('enabled', true)->find($id) ?: false) : false;
        }
        $resolution = new PolicyResolution($winner?->policy ?? ($this->configuredDefault ?: null), $winner, $matches);
        // The policy cache is a materialised view for the UI matrix. Writing it on
        // every resolve means one upsert per fault on the ingestion hot path — heavy
        // during a storm. Callers on that path pass writeCache=false; the per-minute
        // reconcile (and the rebuild command) keep the cache current for alerting
        // interfaces without adding write amplification to ingestion.
        if ($writeCache) {
            try {
                DB::table('iapm_interface_policy_cache')->updateOrInsert(['port_id' => $context->portId], ['policy_id' => $resolution->policy?->id, 'assignment_id' => $winner?->id, 'assignment_source' => $winner?->assignment_type->value ?? ($resolution->policy ? 'configured_default' : null), 'candidate_assignment_ids' => json_encode(array_map(fn (Assignment $assignment) => $assignment->id, $matches)), 'resolved_at' => now()]);
            } catch (\Throwable) { /* installation may not be migrated yet */
            }
        }

        return $resolution;
    }

    /** Build once per resolver instance; exact match types never scan unrelated rows. */
    private function buildIndex(): array
    {
        // Let MariaDB order the topology once. Repeated PHP comparisons against
        // enum casts and Eloquent relations are disproportionately expensive at
        // thousands of assignments, even though the SQL dataset is small.
        $assignments = Assignment::query()
            ->select('iapm_assignments.*')
            ->join('iapm_policies', 'iapm_policies.id', '=', 'iapm_assignments.policy_id')
            ->with(['policy.schedule', 'deviceGroups'])
            ->where('iapm_assignments.enabled', true)
            ->where('iapm_policies.enabled', true)
            ->orderByRaw("CASE iapm_assignments.assignment_type WHEN 'port' THEN 9 WHEN 'port_group' THEN 8 WHEN 'device' THEN 7 WHEN 'device_group' THEN 6 WHEN 'location' THEN 5 WHEN 'ifalias_regex' THEN 4 WHEN 'ifname_regex' THEN 3 WHEN 'interface_type' THEN 2 WHEN 'default' THEN 1 ELSE 0 END DESC")
            ->orderByDesc('iapm_assignments.priority')
            ->orderByDesc('iapm_policies.priority')
            ->orderByDesc('iapm_assignments.updated_at')
            ->get();
        $index = ['all' => [], 'rank' => [], 'regex_patterns' => [], 'port' => [], 'device' => [], 'location' => [], 'port_group' => [], 'device_group' => [], 'interface_type' => [], 'default' => [], 'ifalias_regex' => [], 'ifname_regex' => [], 'device_group_complex' => []];
        $regexLimit = max(1, (int) config('iapm.resolver.max_regex_assignments', 5000));

        foreach ($assignments as $rank => $assignment) {
            $index['all'][$assignment->id] = $assignment;
            $index['rank'][$assignment->id] = $rank;
            $type = $assignment->assignment_type;
            if ($type === AssignmentType::IfAliasRegex || $type === AssignmentType::IfNameRegex) {
                $bucket = $type === AssignmentType::IfAliasRegex ? 'ifalias_regex' : 'ifname_regex';
                if (count($index[$bucket]) >= $regexLimit) {
                    throw new \RuntimeException("Enabled {$bucket} assignments exceed the configured safe limit of {$regexLimit}; none may be silently excluded.");
                }
                $index[$bucket][] = $assignment->id;
                $index['regex_patterns'][$assignment->id] = $assignment->match_expression;
                $this->prepareRegex($assignment->match_expression);

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

    private function rankedUnique(array $ids, array $ranks): array
    {
        $ids = array_values(array_unique($ids));
        if (count($ids) > 1) {
            usort($ids, fn ($left, $right): int => ($ranks[$left] ?? PHP_INT_MAX) <=> ($ranks[$right] ?? PHP_INT_MAX));
        }

        return $ids;
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
        }

        $this->regexSubjectBytes ??= max(1, (int) config('iapm.resolver.regex_subject_bytes', 2048));
        if (strlen($subject) > $this->regexSubjectBytes) {
            return false;
        }
        $limited = $this->prepareRegex($pattern);
        if ($limited === false) {
            return false;
        }

        // Assignment forms/imports validate patterns up front. Suppression still
        // makes legacy or manually edited invalid rows fail closed, without the
        // very high cost of swapping PHP's global error handler for every regex
        // candidate on every interface.
        return @preg_match($limited, $subject) === 1;
    }

    private function prepareRegex(?string $pattern): string|false
    {
        if (! is_string($pattern) || $pattern === '' || strlen($pattern) > 1000) {
            return false;
        }

        return $this->safeRegex[$pattern] ??= $pattern[0]
            .'(*LIMIT_MATCH='.max(1000, (int) config('iapm.resolver.regex_backtrack_limit', 100000)).')'
            .'(*LIMIT_DEPTH='.max(100, (int) config('iapm.resolver.regex_depth_limit', 1000)).')'
            .substr($pattern, 1);
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
