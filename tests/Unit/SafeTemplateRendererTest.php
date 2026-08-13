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
