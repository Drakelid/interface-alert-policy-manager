<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\DTO\InterfaceContext;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SuppressionService;
use PHPUnit\Framework\TestCase;

class SuppressionServiceTest extends TestCase
{
    public function test_an_unsuppressed_interface_has_no_reason(): void
    {
        self::assertNull($this->reason());
    }

    public function test_a_disabled_policy_outranks_every_other_reason(): void
    {
        self::assertSame('policy_disabled', $this->reason(['enabled' => false], deviceDown: true));
    }

    public function test_each_condition_reports_its_own_reason(): void
    {
        self::assertSame('device_down', $this->reason(deviceDown: true));
        self::assertSame('admin_down', $this->reason(context: ['adminStatus' => 'down']));
        self::assertSame('port_ignored', $this->reason(context: ['ignored' => true]));
        self::assertSame('port_disabled', $this->reason(context: ['disabled' => true]));
        self::assertSame('port_deleted', $this->reason(context: ['deleted' => true]));
        self::assertSame('scheduled_maintenance', $this->reason(maintenance: true));
        self::assertSame('parent_down', $this->reason(parentDown: true));
    }

    public function test_a_condition_is_ignored_when_its_policy_switch_is_off(): void
    {
        self::assertNull($this->reason(['suppress_device_down' => false], deviceDown: true));
        self::assertNull($this->reason(['suppress_admin_down' => false], context: ['adminStatus' => 'down']));
        self::assertNull($this->reason(['suppress_maintenance' => false], maintenance: true));
        self::assertNull($this->reason(['suppress_parent_down' => false], parentDown: true));
    }

    public function test_device_down_is_reported_before_admin_down(): void
    {
        self::assertSame('device_down', $this->reason(context: ['adminStatus' => 'down'], deviceDown: true));
    }

    private function reason(array $policyOverrides = [], array $context = [], bool $deviceDown = false, bool $maintenance = false, bool $parentDown = false): ?string
    {
        $policy = new Policy;
        $policy->setRawAttributes(array_merge([
            'enabled' => true,
            'suppress_device_down' => true,
            'suppress_admin_down' => true,
            'suppress_ignored_port' => true,
            'suppress_disabled_port' => true,
            'suppress_deleted_port' => true,
            'suppress_maintenance' => true,
            'suppress_parent_down' => true,
        ], $policyOverrides));

        return (new SuppressionService)->reason($policy, $this->context($context), $deviceDown, $maintenance, $parentDown);
    }

    private function context(array $overrides = []): InterfaceContext
    {
        $values = array_merge([
            'adminStatus' => 'up',
            'operStatus' => 'down',
            'ignored' => false,
            'disabled' => false,
            'deleted' => false,
        ], $overrides);

        return new InterfaceContext(1, 2, 'core-router-01', null, 'xe-0/0/4', 'xe-0/0/4', 'CUST: Example', 'ethernetCsmacd', $values['adminStatus'], $values['operStatus'], $values['ignored'], $values['disabled'], $values['deleted']);
    }
}
