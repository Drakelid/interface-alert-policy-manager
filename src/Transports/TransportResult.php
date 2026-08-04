<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Transports;

readonly class TransportResult
{
    public function __construct(public bool $successful, public ?int $status = null, public ?string $response = null, public ?string $error = null) {}
}
