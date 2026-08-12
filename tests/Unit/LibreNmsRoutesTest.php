<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Support\LibreNmsRoutes;
use PHPUnit\Framework\TestCase;

class LibreNmsRoutesTest extends TestCase
{
    public function test_absolute_port_url_uses_librenms_device_variables(): void
    {
        self::assertSame(
            'https://librenms.example.com/device/device=16/tab=port/port=1143/',
            LibreNmsRoutes::absolutePort('https://librenms.example.com/', 16, 1143)
        );
    }
}
