<?php
namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;
use InvalidArgumentException; use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\UrlGuard; use PHPUnit\Framework\TestCase;
class UrlGuardTest extends TestCase { public function test_file_urls_are_blocked():void{$this->expectException(InvalidArgumentException::class);(new UrlGuard)->assertAllowed('file:///etc/passwd');} public function test_credentials_in_urls_are_blocked():void{$this->expectException(InvalidArgumentException::class);(new UrlGuard)->assertAllowed('https://user:pass@example.com/hook');} public function test_private_addresses_are_blocked_by_default():void{$this->expectException(InvalidArgumentException::class);(new UrlGuard)->assertAllowed('http://127.0.0.1/hook');} }
