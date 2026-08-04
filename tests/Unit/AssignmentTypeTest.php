<?php
namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\AssignmentType; use PHPUnit\Framework\TestCase;
class AssignmentTypeTest extends TestCase { public function test_required_precedence():void{$ordered=[AssignmentType::Port,AssignmentType::PortGroup,AssignmentType::Device,AssignmentType::DeviceGroup,AssignmentType::Location,AssignmentType::IfAliasRegex,AssignmentType::IfNameRegex,AssignmentType::InterfaceType,AssignmentType::Default];self::assertSame([9,8,7,6,5,4,3,2,1],array_map(fn($v)=>$v->specificity(),$ordered));} }
