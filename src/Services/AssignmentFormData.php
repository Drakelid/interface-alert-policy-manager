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

        return [
            'assignment' => $assignment,
            'deviceGroups' => DeviceGroup::orderBy('name')->get(['id', 'name']),
            'locations' => Location::orderBy('location')->get(['id', 'location']),
            'portGroups' => PortGroup::orderBy('name')->get(['id', 'name']),
            'deviceLabel' => $device ? app(EntityLookup::class)->deviceLabel($device) : '',
            'portLabel' => $port ? app(EntityLookup::class)->portLabel($port) : '',
        ];
    }
}
