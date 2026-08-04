<?php
namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident; use PHPUnit\Framework\TestCase;
class IncidentKeyTest extends TestCase { public function test_key_uses_stable_numeric_identifiers():void{self::assertSame('interface-down:225:17292',Incident::key(225,17292));} }
