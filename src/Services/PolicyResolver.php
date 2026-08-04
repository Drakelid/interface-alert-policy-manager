<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\DTO\InterfaceContext;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\DTO\PolicyResolution;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\AssignmentType;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use Illuminate\Support\Facades\DB;

class PolicyResolver
{
    private ?\Illuminate\Support\Collection $assignments = null;
    private Policy|false|null $configuredDefault = null;

    public function __construct(private readonly SettingStore $settings) {}

    public function resolve(InterfaceContext $context): PolicyResolution
    {
        $matches = ($this->assignments ??= Assignment::query()->with(['policy.schedule', 'deviceGroups'])->where('enabled', true)->whereHas('policy', fn ($q) => $q->where('enabled', true))->get())->filter(fn (Assignment $a) => $this->matches($a, $context))->sort(function (Assignment $a, Assignment $b): int {
            return [$b->assignment_type->specificity(), $b->priority, $b->policy->priority, $b->updated_at?->timestamp ?? 0] <=> [$a->assignment_type->specificity(), $a->priority, $a->policy->priority, $a->updated_at?->timestamp ?? 0];
        })->values();
        $winner = $matches->first();
        if (! $winner && $this->configuredDefault === null) { $id = $this->settings->get('default_policy_id'); $this->configuredDefault = $id ? (Policy::where('enabled', true)->find($id) ?: false) : false; }
        $resolution = new PolicyResolution($winner?->policy ?? ($this->configuredDefault ?: null), $winner, $matches->all());
        try { DB::table('iapm_interface_policy_cache')->updateOrInsert(['port_id' => $context->portId], ['policy_id' => $resolution->policy?->id, 'assignment_id' => $winner?->id, 'assignment_source' => $winner?->assignment_type->value ?? ($resolution->policy ? 'configured_default' : null), 'candidate_assignment_ids' => json_encode($matches->pluck('id')->all()), 'resolved_at' => now()]); } catch (\Throwable) { /* installation may not be migrated yet */ }
        return $resolution;
    }

    private function matches(Assignment $a, InterfaceContext $c): bool
    {
        return match ($a->assignment_type) {
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

    private function regex(?string $pattern, string $subject): bool { if (! $pattern || strlen($pattern) > 1000) return false; set_error_handler(static fn () => true); try { return preg_match($pattern, $subject, $m, PREG_UNMATCHED_AS_NULL) === 1; } finally { restore_error_handler(); } }
    private function groups(Assignment $a, array $actual): bool { $selected = $a->deviceGroups->pluck('device_group_id')->map(fn ($id) => (int) $id)->all(); return match ($a->match_mode) { 'all' => array_diff($selected, $actual) === [], 'exclude' => array_intersect($selected, $actual) === [], default => array_intersect($selected, $actual) !== [] }; }
}
