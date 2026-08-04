<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Transports;

interface NotificationTransport
{
    public function send(array $configuration, string $receiver, string $message): TransportResult;
}
