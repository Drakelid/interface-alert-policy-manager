<?php
namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ReceiverResolver; use PHPUnit\Framework\TestCase;
class ReceiverResolverTest extends TestCase { public function test_first_populated_level_wins_and_values_are_deduplicated():void{self::assertSame(['+47 12345678'],(new ReceiverResolver)->resolve([],['+47 12345678','+47 12345678'],['fallback']));} public function test_invalid_receivers_are_rejected():void{self::assertSame([],(new ReceiverResolver)->resolve(["bad\nheader"]));} }
