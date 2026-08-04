<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use App\Models\Port;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\DTO\InterfaceContext;

class InterfaceContextService
{
    public function forPort(Port $port): InterfaceContext
    {
        $port->loadMissing(['device.location', 'device.groups', 'groups']);
        $admin = $port->ifAdminStatus instanceof \BackedEnum ? $port->ifAdminStatus->value : (string) $port->ifAdminStatus;
        $oper = $port->ifOperStatus instanceof \BackedEnum ? $port->ifOperStatus->value : (string) $port->ifOperStatus;
        return new InterfaceContext((int) $port->device_id, (int) $port->port_id, (string) $port->device->hostname, $port->device->location_id ? (int) $port->device->location_id : null, (string) $port->ifName, (string) $port->ifDescr, (string) $port->ifAlias, (string) $port->ifType, $admin, $oper, (bool) $port->ignore, (bool) $port->disabled, (bool) $port->deleted, $port->device->groups->modelKeys(), $port->groups->modelKeys(), (string) $port->device->sysName, (string) $port->device->displayName(), (string) ($port->device->location?->location ?? ''));
    }
}
