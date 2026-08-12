<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\DTO;

readonly class InterfaceContext
{
    public function __construct(public int $deviceId, public int $portId, public string $hostname, public ?int $locationId, public string $ifName, public string $ifDescr, public string $ifAlias, public string $ifType, public string $adminStatus, public string $operStatus, public bool $ignored, public bool $disabled, public bool $deleted, public array $deviceGroupIds = [], public array $portGroupIds = [], public string $sysName = '', public string $displayName = '', public string $location = '', public array $deviceGroupNames = []) {}
}
