<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Location;
use App\Models\Port;
use App\Models\PortGroup;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;

class AssignmentFormData
{
    /** @return array<string, mixed> */
    public function for(Assignment $assignment): array
    {
        $deviceRef = old('assignment_type', $assignment->assignment_type?->value) === 'device'
            ? old('assignment_reference', $assignment->assignment_reference)
            : null;
        $device = $deviceRef ? Device::find($deviceRef) : null;

        $portRef = old('assignment_type', $assignment->assignment_type?->value) === 'port'
            ? old('assignment_reference', $assignment->assignment_reference)
            : null;
        $port = $portRef ? Port::with('device')->find($portRef) : null;
        $excludedIds = array_slice((array) old('excluded_device_ids', $assignment->metadata_json['excluded_device_ids'] ?? []), 0, 1000);
        $excludedIds = array_filter($excludedIds, fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)));

        return [
            'assignment' => $assignment,
            'deviceGroups' => DeviceGroup::orderBy('name')->get(['id', 'name']),
            'locations' => Location::orderBy('location')->get(['id', 'location']),
            'portGroups' => PortGroup::orderBy('name')->get(['id', 'name']),
            'deviceLabel' => $device ? app(EntityLookup::class)->deviceLabel($device) : '',
            'portLabel' => $port ? app(EntityLookup::class)->portLabel($port) : '',
            'excludedDevices' => Device::whereIn('device_id', $excludedIds)
                ->orderBy('hostname')->get(['device_id', 'hostname', 'sysName'])
                ->map(fn ($excluded) => ['id' => (int) $excluded->device_id, 'label' => app(EntityLookup::class)->deviceLabel($excluded)])->all(),
        ];
    }
}
