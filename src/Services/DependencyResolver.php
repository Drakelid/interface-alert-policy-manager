<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use App\Models\Device;
use App\Models\Port;

/**
 * Root-cause suppression: when an uplink interface on a device is down, the
 * customer/child interfaces behind it are down as a consequence, so their
 * incidents can be suppressed to avoid an alert storm.
 *
 * Uplinks are designated by a LibreNMS port group (the uplink_port_group_id
 * setting). The feature is inert unless that setting is configured AND a policy
 * enables suppress_uplink_down.
 */
class DependencyResolver
{
    /** @var array<int, list<int>> device id => down uplink port ids */
    private array $cache = [];

    public function __construct(private readonly SettingStore $settings) {}

    public function uplinkDown(Device $device, ?int $excludePortId = null): bool
    {
        $groupId = (int) $this->settings->get('uplink_port_group_id', 0);
        if ($groupId <= 0) {
            return false;
        }

        $deviceId = (int) $device->device_id;
        $downUplinks = $this->cache[$deviceId] ??= Port::query()
            ->where('device_id', $deviceId)
            ->where('deleted', 0)
            ->where('ifOperStatus', '!=', 'up') // raw column comparison, bypassing the enum cast
            ->whereHas('groups', fn ($g) => $g->where('port_groups.id', $groupId))
            ->pluck('port_id')->map(fn ($id) => (int) $id)->all();

        return collect($downUplinks)->contains(fn (int $portId): bool => $portId !== $excludePortId);
    }
}
