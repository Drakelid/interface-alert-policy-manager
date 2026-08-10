<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\DTO\PolicyResolution;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;

class ReceiverResolver
{
    public function __construct(private readonly ?SettingStore $settings = null) {}

    /**
     * Resolve the receiver list using the single documented precedence chain:
     * action override, winning assignment, policy default, destination list/default,
     * then global default. Metadata on losing candidates is deliberately ignored.
     */
    public function forAction(PolicyAction $action, ?PolicyResolution $resolution = null, ?Incident $incident = null): array
    {
        $policy = $resolution?->policy ?? $incident?->policy ?? $action->policy;
        $assignmentReceivers = $resolution
            ? (array) ($resolution->winner?->metadata_json['receivers'] ?? [])
            : (array) ($incident?->context_json['assignment_receivers'] ?? []);
        $configuration = (array) ($action->destination?->configuration_encrypted ?? []);

        return $this->resolve(
            (array) $action->receivers_json,
            $assignmentReceivers,
            [(string) ($policy?->default_receiver ?? '')],
            (array) ($configuration['receivers'] ?? []),
            [(string) ($configuration['default_receiver'] ?? '')],
            [(string) ($this->settings?->get('sms_default_receiver', config('iapm.sms.default_receiver')) ?? config('iapm.sms.default_receiver'))],
        );
    }

    public function assignmentReceivers(PolicyResolution $resolution): array
    {
        return $this->normalizeMany((array) ($resolution->winner?->metadata_json['receivers'] ?? []));
    }

    /**
     * Readiness has no concrete interface/winning assignment yet. Evaluate the
     * union of possible enabled-assignment receivers through the same precedence
     * and validation rules used for live deliveries.
     */
    public function forReadiness(PolicyAction $action): array
    {
        $policy = $action->policy;
        $configuration = (array) $action->destination->configuration_encrypted;
        $assignmentReceivers = $policy->assignments
            ->where('enabled', true)
            ->flatMap(fn ($assignment) => (array) ($assignment->metadata_json['receivers'] ?? []))
            ->all();

        return $this->resolve(
            (array) $action->receivers_json,
            $assignmentReceivers,
            [(string) ($policy->default_receiver ?? '')],
            (array) ($configuration['receivers'] ?? []),
            [(string) ($configuration['default_receiver'] ?? '')],
            [(string) ($this->settings?->get('sms_default_receiver', config('iapm.sms.default_receiver')) ?? config('iapm.sms.default_receiver'))],
        );
    }

    public function resolve(array ...$levels): array
    {
        foreach ($levels as $receivers) {
            $normalized = $this->normalizeMany($receivers);
            if ($normalized !== []) {
                return $normalized;
            }
        }

        return [];
    }

    private function normalizeMany(array $receivers): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($value) => $this->normalize((string) $value), $receivers))));
    }

    private function normalize(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' && mb_strlen($value) <= 128 && preg_match('/^[\pL\pN+_.@() \-]+$/u', $value) ? $value : null;
    }
}
