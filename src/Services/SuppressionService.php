<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use App\Models\Device;
use LibreNMS\Enum\MaintenanceStatus;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\DTO\InterfaceContext;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;

class SuppressionService
{
    public function __construct(private readonly ScheduleEvaluator $schedules) {}

    public function reason(Policy $policy, InterfaceContext $context, bool $deviceDown = false, bool $maintenance = false, bool $parentDown = false, bool $uplinkDown = false): ?string
    {
        $policy->loadMissing('schedule');
        return match (true) { ! $policy->enabled => 'policy_disabled', $policy->suppress_device_down && $deviceDown => 'device_down', $policy->suppress_admin_down && $context->adminStatus !== 'up' => 'admin_down', $policy->suppress_ignored_port && $context->ignored => 'port_ignored', $policy->suppress_disabled_port && $context->disabled => 'port_disabled', $policy->suppress_deleted_port && $context->deleted => 'port_deleted', $policy->suppress_maintenance && $maintenance => 'scheduled_maintenance', $policy->suppress_parent_down && $parentDown => 'parent_down', $policy->suppress_uplink_down && $uplinkDown => 'uplink_down', ! $this->schedules->permits($policy->schedule) => 'outside_schedule', default => null };
    }

    /**
     * A device can be inside a maintenance window whose behavior still tells
     * LibreNMS to alert, so isUnderMaintenance() alone is too broad here.
     */
    public static function maintenanceSuppresses(Device $device): bool
    {
        return in_array($device->getMaintenanceStatus(), [MaintenanceStatus::SkipAlerts, MaintenanceStatus::MuteAlerts], true);
    }

    /** @param  \Illuminate\Support\Collection<int, Device>|iterable<Device>  $parents */
    public static function anyParentDown(iterable $parents): bool
    {
        foreach ($parents as $parent) {
            if (! (bool) $parent->status) {
                return true;
            }
        }

        return false;
    }
}
