<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums;

enum ActionPhase: string
{
    case Trigger = 'trigger';
    case Escalation = 'escalation';
    case Reminder = 'reminder';
    case Recovery = 'recovery';
    case Acknowledged = 'acknowledged';
}
