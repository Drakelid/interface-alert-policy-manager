<?php
namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\Redactor; use PHPUnit\Framework\TestCase;
class RedactorTest extends TestCase { public function test_nested_secrets_are_redacted():void{$v=(new Redactor)->array(['url'=>'https://example.test','auth'=>['password'=>'secret','token'=>'token']]);self::assertSame('[REDACTED]',$v['auth']['password']);self::assertSame('[REDACTED]',$v['auth']['token']);} public function test_text_is_truncated_and_redacted():void{self::assertStringNotContainsString('hunter2',(new Redactor)->text('password=hunter2'));} }
