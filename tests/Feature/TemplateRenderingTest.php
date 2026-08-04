<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\ProcessActionsCommand;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SafeTemplateRenderer;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\TemplateContextBuilder;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class TemplateRenderingTest extends IntegrationTestCase
{
    /** Every placeholder the documentation promises must resolve. */
    private const DOCUMENTED_PLACEHOLDERS = [
        'incident_id', 'severity', 'state', 'hostname', 'sysName', 'display_name', 'device_id',
        'port_id', 'ifName', 'ifDescr', 'ifAlias', 'ifAdminStatus', 'ifOperStatus', 'location',
        'policy_name', 'assignment_source', 'first_seen_at', 'triggered_at', 'recovered_at',
        'outage_duration', 'device_url', 'port_url', 'acknowledgement_user', 'suppression_reason',
    ];

    public function test_every_documented_placeholder_resolves_for_an_incident(): void
    {
        $incident = $this->incident($this->policy(['name' => 'Core policy']), $this->downPort($this->device()));
        $values = app(TemplateContextBuilder::class)->forIncident($incident->load('policy'));

        foreach (self::DOCUMENTED_PLACEHOLDERS as $placeholder) {
            self::assertArrayHasKey($placeholder, $values, "Placeholder $placeholder is documented but unavailable.");
        }

        $template = implode("\n", array_map(fn ($name) => "$name={{ $name }}", self::DOCUMENTED_PLACEHOLDERS));
        $rendered = app(SafeTemplateRenderer::class)->render($template, $values);

        self::assertStringContainsString('policy_name=Core policy', $rendered);
        self::assertStringContainsString('hostname='.$incident->context_json['hostname'], $rendered);
    }

    public function test_the_default_templates_render_against_a_real_incident(): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()), ['state' => IncidentState::Recovered, 'recovered_at' => now()]);
        $values = app(TemplateContextBuilder::class)->forIncident($incident->load('policy'));
        $renderer = app(SafeTemplateRenderer::class);

        foreach (['trigger', 'escalation', 'reminder', 'recovery', 'acknowledged'] as $phase) {
            $rendered = $renderer->render(ProcessActionsCommand::defaultTemplate($phase), $values);
            self::assertStringContainsString((string) $incident->id, $rendered);
        }
    }

    public function test_the_device_and_port_urls_use_the_configured_url_base(): void
    {
        $this->settings->put('url_base', 'https://librenms.example.com/');
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));

        $values = app(TemplateContextBuilder::class)->forIncident($incident);

        self::assertSame("https://librenms.example.com/device/{$incident->device_id}", $values['device_url']);
        self::assertSame("https://librenms.example.com/device/{$incident->device_id}/port/{$incident->port_id}", $values['port_url']);
    }

    public function test_the_acknowledgement_user_resolves_to_a_username(): void
    {
        $admin = $this->admin();
        $incident = $this->incident($this->policy(), $this->downPort($this->device()), ['state' => IncidentState::Acknowledged, 'acknowledged_at' => now(), 'acknowledged_by' => $admin->user_id]);

        self::assertSame($admin->username, app(TemplateContextBuilder::class)->forIncident($incident)['acknowledgement_user']);
    }

    public function test_an_unknown_placeholder_fails_the_delivery_instead_of_rendering_empty(): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));
        $values = app(TemplateContextBuilder::class)->forIncident($incident);

        $this->expectException(\InvalidArgumentException::class);
        app(SafeTemplateRenderer::class)->render('Down: {{ not_a_placeholder }}', $values);
    }

    public function test_an_over_long_message_is_truncated_deterministically_and_keeps_the_incident_id(): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));
        $values = app(TemplateContextBuilder::class)->forIncident($incident);

        $template = str_repeat('detail ', 200).'{{ incident_id }}';
        $first = app(SafeTemplateRenderer::class)->render($template, $values, 160);
        $second = app(SafeTemplateRenderer::class)->render($template, $values, 160);

        self::assertLessThanOrEqual(160, mb_strlen($first));
        self::assertSame($first, $second, 'Truncation must be deterministic.');
        self::assertStringEndsWith("Incident: {$incident->id}", $first);
    }

    public function test_preview_values_cover_the_same_placeholders_as_delivery(): void
    {
        $port = $this->downPort($this->device());
        $context = app(\LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService::class)->forPort($port);

        $preview = app(TemplateContextBuilder::class)->forPreview($context);
        $incident = app(TemplateContextBuilder::class)->forIncident($this->incident($this->policy(), $port));

        self::assertSame([], array_diff(array_keys($incident), array_keys($preview)), 'The preview must not be missing placeholders that delivery provides.');
    }
}
