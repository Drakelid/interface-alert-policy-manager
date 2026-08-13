<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use InvalidArgumentException;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SafeTemplateRenderer;
use PHPUnit\Framework\TestCase;

class SafeTemplateRendererTest extends TestCase
{
    public function test_it_renders_known_values_without_executing_code(): void
    {
        $r = new SafeTemplateRenderer;
        self::assertSame('Port xe-0/0/1 {{ phpinfo() }}', $r->render('Port {{ ifName }} {{ phpinfo() }}', ['ifName' => 'xe-0/0/1']));
    }

    public function test_unknown_placeholders_fail_visibly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SafeTemplateRenderer)->render('{{ missing }}', []);
    }

    public function test_truthy_conditions_and_else_branches_render(): void
    {
        $renderer = new SafeTemplateRenderer;
        $template = '{{#if ifAlias}}Circuit {{ ifAlias }}{{else}}Port {{ ifName }}{{/if}}';

        self::assertSame('Circuit Customer A', $renderer->render($template, ['ifAlias' => 'Customer A', 'ifName' => 'Gi0/1']));
        self::assertSame('Port Gi0/1', $renderer->render($template, ['ifAlias' => '', 'ifName' => 'Gi0/1']));
    }

    public function test_string_comparisons_and_nested_conditions_render(): void
    {
        $template = '{{#if severity == "critical"}}URGENT {{#if location != "HQ"}}{{ location }} {{/if}}{{/if}}{{ ifName }}';

        self::assertSame('URGENT Branch Gi0/1', (new SafeTemplateRenderer)->render($template, [
            'severity' => 'critical', 'location' => 'Branch', 'ifName' => 'Gi0/1',
        ]));
    }

    public function test_contains_conditions_support_device_group_membership(): void
    {
        $renderer = new SafeTemplateRenderer;
        $values = ['device_groups' => 'Core routers, Production'];

        self::assertSame('CORE', $renderer->render('{{#if device_groups contains "Core routers"}}CORE{{else}}OTHER{{/if}}', $values));
        self::assertSame('PROD', $renderer->render('{{#if device_groups == "Production"}}PROD{{else}}OTHER{{/if}}', $values));
        self::assertSame('OTHER', $renderer->render('{{#if device_groups contains "NonProduction"}}WRONG{{else}}OTHER{{/if}}', $values));
        self::assertSame('YES', $renderer->render('{{#if device_groups not contains "Branches"}}YES{{/if}}', $values));
    }

    public function test_and_combines_multiple_conditions(): void
    {
        $renderer = new SafeTemplateRenderer;
        $template = '{{#if ifAdminStatus == up && ifOperStatus == down}}down{{else}}not down{{/if}}';

        self::assertSame('down', $renderer->render($template, ['ifAdminStatus' => 'up', 'ifOperStatus' => 'down']));
        self::assertSame('not down', $renderer->render($template, ['ifAdminStatus' => 'down', 'ifOperStatus' => 'down']));
        self::assertSame('not down', $renderer->render($template, ['ifAdminStatus' => 'up', 'ifOperStatus' => 'up']));
    }

    public function test_and_supports_quoted_values_and_contains_conditions(): void
    {
        $template = '{{#if device_groups contains "Core routers" && severity != warning}}YES{{else}}NO{{/if}}';

        self::assertSame('YES', (new SafeTemplateRenderer)->render($template, [
            'device_groups' => 'Core routers, Production', 'severity' => 'critical',
        ]));
    }

    public function test_an_empty_and_operand_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SafeTemplateRenderer)->render('{{#if enabled && }}invalid{{/if}}', ['enabled' => true]);
    }

    public function test_unknown_placeholder_in_an_unselected_branch_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SafeTemplateRenderer)->render('{{#if enabled}}ok{{else}}{{ missing }}{{/if}}', ['enabled' => true]);
    }

    public function test_unclosed_condition_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SafeTemplateRenderer)->render('{{#if ifAlias}}Alias', ['ifAlias' => 'Customer A']);
    }

    public function test_rendering_does_not_truncate_long_messages(): void
    {
        $renderer = new SafeTemplateRenderer;
        $template = str_repeat('x', 300)."\nDescription: remote distribution switch with complete useful details";
        $message = $renderer->render($template, ['incident_id' => 42]);

        self::assertSame($template, $message);
        self::assertGreaterThan(300, mb_strlen($message));
    }
}
