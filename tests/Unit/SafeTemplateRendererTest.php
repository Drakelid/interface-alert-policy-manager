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

    public function test_truncation_preserves_incident_identifier(): void
    {
        $v = (new SafeTemplateRenderer)->render(str_repeat('x', 100), ['incident_id' => 42], 30);
        self::assertLessThanOrEqual(30, mb_strlen($v));
        self::assertStringEndsWith('Incident: 42', $v);
    }

    public function test_gsm_and_unicode_segment_sizes_are_calculated_correctly(): void
    {
        $renderer = new SafeTemplateRenderer;

        self::assertSame(['encoding' => 'GSM-7', 'units' => 160, 'segments' => 1, 'single_limit' => 160], $renderer->smsMetrics(str_repeat('a', 160)));
        self::assertSame(2, $renderer->smsMetrics(str_repeat('a', 161))['segments']);
        self::assertSame(160, $renderer->smsMetrics(str_repeat('^', 80))['units'], 'GSM extension-table characters use two septets.');
        self::assertSame('GSM-7', $renderer->smsMetrics('æøå ÆØÅ é')['encoding']);
        self::assertSame(['encoding' => 'Unicode', 'units' => 70, 'segments' => 1, 'single_limit' => 70], $renderer->smsMetrics(str_repeat('漢', 70)));
        self::assertSame(2, $renderer->smsMetrics(str_repeat('漢', 71))['segments']);
        self::assertSame(2, $renderer->smsMetrics('😀')['units'], 'Emoji consume a UTF-16 surrogate pair.');
    }

    public function test_single_sms_rendering_truncates_to_one_segment_and_keeps_the_incident(): void
    {
        $renderer = new SafeTemplateRenderer;
        $message = $renderer->renderSingleSms(str_repeat('x', 300), ['incident_id' => 42]);

        self::assertSame(1, $renderer->smsMetrics($message)['segments']);
        self::assertStringEndsWith("...\nIncident: 42", $message);
    }
}
