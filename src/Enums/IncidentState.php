<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums;

enum IncidentState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Acknowledged = 'acknowledged';
    case Suppressed = 'suppressed';
    case Recovered = 'recovered';
}
