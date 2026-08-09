<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\DTO;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;

readonly class PolicyResolution
{
    /** @param list<Assignment> $candidates */
    public function __construct(public ?Policy $policy, public ?Assignment $winner, public array $candidates) {}
}
