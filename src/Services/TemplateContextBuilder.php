<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use App\Models\Device;
use App\Models\User;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\DTO\InterfaceContext;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Support\LibreNmsRoutes;

/**
 * Builds the placeholder map consumed by SafeTemplateRenderer.
 *
 * Every documented placeholder must be present here; SafeTemplateRenderer
 * rejects unknown placeholders rather than silently rendering them empty.
 */
class TemplateContextBuilder
{
    /** @var array<int, string> */
    private array $userNames = [];

    /** @var array<int, list<string>> */
    private array $legacyDeviceGroupNames = [];

    private ?string $urlBase = null;

    public function __construct(private readonly SettingStore $settings) {}

    public function forIncident(Incident $incident): array
    {
        $context = (array) $incident->context_json;
        if (! isset($context['deviceGroupNames']) || ! is_array($context['deviceGroupNames'])) {
            $context['deviceGroupNames'] = $this->currentDeviceGroupNames((int) $incident->device_id);
        }
        $recoveredAt = $incident->recovered_at;
        $firstSeenAt = $incident->first_seen_at;

        return $this->placeholders($context, [
            'incident_id' => (int) $incident->id,
            'device_id' => (int) $incident->device_id,
            'port_id' => (int) $incident->port_id,
            'severity' => $incident->severity?->value ?? '',
            'state' => $incident->state?->value ?? '',
            'policy_name' => (string) ($incident->policy?->name ?? ''),
            'first_seen_at' => $this->moment($firstSeenAt),
            'triggered_at' => $this->moment($incident->triggered_at),
            'recovered_at' => $this->moment($recoveredAt),
            'outage_duration' => $firstSeenAt ? $firstSeenAt->diffForHumans($recoveredAt, true) : '',
            'acknowledgement_user' => $this->userName($incident->acknowledged_by),
            'suppression_reason' => (string) ($incident->suppression_reason ?? ''),
        ]);
    }

    /** A synthetic placeholder map covering every placeholder, for template validation. */
    public function sample(): array
    {
        return $this->forPreview(new InterfaceContext(1, 2, 'core-router-01', null, 'xe-0/0/4', 'xe-0/0/4', 'CUST: Example customer', 'ethernetCsmacd', 'up', 'down', false, false, false, [], [], 'core-router-01', 'core-router-01', 'HQ', ['Core routers', 'Production']));
    }

    /**
     * The placeholder names the interface templates accept, in the order the UI
     * lists them as click-to-insert chips (P2-1 / P4-4). Kept in step with
     * placeholders() by TemplatePlaceholderTest, which compares this against the
     * keys the builder actually produces — a chip that inserts an unknown
     * placeholder would fail validation on save.
     *
     * @var list<string>
     */
    public const INTERFACE_PLACEHOLDERS = [
        'hostname', 'sysName', 'display_name', 'ifName', 'ifDescr', 'ifAlias',
        'ifAdminStatus', 'ifOperStatus', 'interface_type', 'location', 'device_groups',
        'severity', 'state', 'policy_name', 'assignment_source',
        'first_seen_at', 'triggered_at', 'recovered_at', 'outage_duration',
        'acknowledgement_user', 'suppression_reason',
        'device_id', 'port_id', 'incident_id', 'device_url', 'port_url',
    ];

    /** The device-digest template's own, different set. */
    public const DIGEST_PLACEHOLDERS = [
        'hostname', 'device_id', 'device_groups', 'interface_count', 'interfaces', 'severity', 'first_seen_at', 'device_url',
    ];

    /** Placeholder map for the device-digest template (a different, device-level set). */
    public function digestSample(): array
    {
        return [
            'severity' => 'critical',
            'hostname' => 'core-router-01',
            'device_id' => 1,
            'device_groups' => 'Core routers, Production',
            'interface_count' => 42,
            'interfaces' => 'xe-0/0/1, xe-0/0/2, xe-0/0/3 +39 more',
            'first_seen_at' => now()->subMinutes(2)->format('Y-m-d H:i:s'),
            'device_url' => rtrim((string) ($this->settings->get('url_base') ?: config('app.url', '')), '/').'/device/1',
        ];
    }

    public function forPreview(InterfaceContext $context, string $policyName = 'Preview policy'): array
    {
        return $this->placeholders((array) $context, [
            'incident_id' => 12345,
            'device_id' => $context->deviceId,
            'port_id' => $context->portId,
            'severity' => 'critical',
            'state' => 'active',
            'policy_name' => $policyName,
            'first_seen_at' => $this->moment(now()->subMinutes(12)),
            'triggered_at' => $this->moment(now()->subMinutes(10)),
            'recovered_at' => '',
            'outage_duration' => '12 minutes',
            'acknowledgement_user' => 'Preview user',
            'suppression_reason' => '',
        ]);
    }

    /**
     * @param  array  $context  the serialized InterfaceContext stored on the incident
     * @param  array  $incidentValues  values that only an incident (or a preview stand-in) can supply
     */
    private function placeholders(array $context, array $incidentValues): array
    {
        $deviceId = (int) ($incidentValues['device_id'] ?? 0);
        $portId = (int) ($incidentValues['port_id'] ?? 0);
        $hostname = (string) ($context['hostname'] ?? '');
        $base = rtrim((string) ($this->urlBase ??= (string) ($this->settings->get('url_base') ?: config('app.url', ''))), '/');

        return array_merge($incidentValues, [
            'hostname' => $hostname,
            'sysName' => (string) (($context['sysName'] ?? '') ?: $hostname),
            'display_name' => (string) (($context['displayName'] ?? '') ?: $hostname),
            'ifName' => (string) ($context['ifName'] ?? ''),
            'ifDescr' => (string) ($context['ifDescr'] ?? ''),
            'ifAlias' => (string) ($context['ifAlias'] ?? ''),
            'ifAdminStatus' => (string) ($context['adminStatus'] ?? ''),
            'ifOperStatus' => (string) ($context['operStatus'] ?? ''),
            'interface_type' => (string) ($context['ifType'] ?? ''),
            'location' => (string) ($context['location'] ?? ''),
            'device_groups' => $this->deviceGroups($context['deviceGroupNames'] ?? []),
            'assignment_source' => (string) ($context['assignment_source'] ?? ''),
            'device_url' => $base === '' ? '' : "$base/device/$deviceId",
            'port_url' => $base === '' ? '' : LibreNmsRoutes::absolutePort($base, $deviceId, $portId),
        ]);
    }

    private function moment(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : '';
    }

    private function deviceGroups(mixed $groups): string
    {
        if (! is_array($groups)) {
            return '';
        }

        return collect($groups)
            ->filter(fn ($name) => is_scalar($name) && trim((string) $name) !== '')
            ->map(fn ($name) => trim((string) $name))
            ->unique()
            ->sort()
            ->implode(', ');
    }

    /** @return list<string> */
    private function currentDeviceGroupNames(int $deviceId): array
    {
        if (array_key_exists($deviceId, $this->legacyDeviceGroupNames)) {
            return $this->legacyDeviceGroupNames[$deviceId];
        }

        try {
            $device = Device::with('groups')->find($deviceId);
            $names = $device?->groups
                ->pluck('name')
                ->filter(fn ($name) => is_scalar($name) && trim((string) $name) !== '')
                ->map(fn ($name) => trim((string) $name))
                ->unique()
                ->sort()
                ->values()
                ->all() ?? [];
        } catch (\Throwable) {
            $names = [];
        }

        return $this->legacyDeviceGroupNames[$deviceId] = $names;
    }

    private function userName(?int $userId): string
    {
        if (! $userId) {
            return '';
        }

        return $this->userNames[$userId] ??= (string) (User::find($userId)?->username ?? '');
    }
}
