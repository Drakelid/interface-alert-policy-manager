<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use InvalidArgumentException;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Transports\GenericWebhookTransport;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Transports\NotificationTransport;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Transports\SmsGatewayTransport;

class TransportManager
{
    public function __construct(private readonly SmsGatewayTransport $sms, private readonly GenericWebhookTransport $webhook) {}

    public function for(string $type): NotificationTransport
    {
        return match ($type) {
            'sms_gateway' => $this->sms, 'generic_webhook' => $this->webhook, default => throw new InvalidArgumentException("Unsupported destination type: $type")
        };
    }
}
