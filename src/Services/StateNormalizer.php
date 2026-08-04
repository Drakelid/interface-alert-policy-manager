<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use InvalidArgumentException;
use LibreNMS\Enum\AlertState;

class StateNormalizer
{
    public function normalize(int|string $state): string
    {
        $value = is_string($state) ? strtolower(trim($state)) : $state;
        return match ($value) {
            AlertState::ACTIVE, AlertState::WORSE, AlertState::BETTER, AlertState::CHANGED, 'active', 'alert', 'firing' => 'active',
            AlertState::ACKNOWLEDGED, 'acknowledged', 'ack' => 'acknowledged',
            AlertState::RECOVERED, 'recovered', 'clear', 'cleared', 'resolved', 'ok' => 'recovered',
            default => throw new InvalidArgumentException('Unsupported LibreNMS alert state.'),
        };
    }
}
