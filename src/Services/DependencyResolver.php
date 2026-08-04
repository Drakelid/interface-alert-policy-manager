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
    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(private readonly SettingStore $settings) {}

    public function uplinkDown(Device $device, ?int $excludePortId = null): bool
    {
        $groupId = (int) $this->settings->get('uplink_port_group_id', 0);
        if ($groupId <= 0) {
            return false;
        }

        $key = $device->device_id.':'.$excludePortId;

        return $this->cache[$key] ??= Port::query()
            ->where('device_id', $device->device_id)
            ->where('deleted', 0)
            ->when($excludePortId, fn ($q, $id) => $q->where('port_id', '!=', $id))
            ->where('ifOperStatus', '!=', 'up') // raw column comparison, bypassing the enum cast
            ->whereHas('groups', fn ($g) => $g->where('port_groups.id', $groupId))
            ->exists();
    }
}
