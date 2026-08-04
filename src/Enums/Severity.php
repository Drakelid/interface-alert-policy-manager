<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
