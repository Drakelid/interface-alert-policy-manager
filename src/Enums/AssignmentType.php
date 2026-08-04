<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums;

enum AssignmentType: string
{
    case Port = 'port';
    case PortGroup = 'port_group';
    case Device = 'device';
    case DeviceGroup = 'device_group';
    case Location = 'location';
    case IfAliasRegex = 'ifalias_regex';
    case IfNameRegex = 'ifname_regex';
    case InterfaceType = 'interface_type';
    case Default = 'default';

    public function specificity(): int
    {
        return match ($this) {
            self::Port => 9, self::PortGroup => 8, self::Device => 7,
            self::DeviceGroup => 6, self::Location => 5, self::IfAliasRegex => 4,
            self::IfNameRegex => 3, self::InterfaceType => 2, self::Default => 1,
        };
    }
}
