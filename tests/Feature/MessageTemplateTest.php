<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class MessageTemplateTest extends IntegrationTestCase
{
    public function test_a_custom_default_template_is_used_when_the_action_has_none(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('template_trigger', 'CUSTOM DOWN {{ ifName }}');
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions');

        Http::assertSent(fn ($request) => str_contains($request['message'], 'CUSTOM DOWN'));
    }

    public function test_a_per_action_template_overrides_the_custom_default(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->settings->put('template_trigger', 'CUSTOM DOWN');
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination(), ['message_template' => 'ACTION SPECIFIC {{ ifName }}']);
        $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions');

        Http::assertSent(fn ($request) => str_contains($request['message'], 'ACTION SPECIFIC') && ! str_contains($request['message'], 'CUSTOM DOWN'));
    }

    public function test_if_else_logic_is_used_by_real_delivery(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination(), [
            'message_template' => '{{#if ifAlias}}Circuit {{ ifAlias }}{{else}}Port {{ ifName }}{{/if}}',
        ]);
        $this->incident($policy, $this->downPort($this->device(), ['ifAlias' => 'Customer A']));

        $this->artisan('iapm:process-actions');

        Http::assertSent(fn ($request) => $request['message'] === 'Circuit Customer A');
    }

    public function test_generic_webhook_templates_are_not_truncated_by_sms_limits(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $destination = Destination::create([
            'name' => 'Long webhook',
            'type' => 'generic_webhook',
            'enabled' => true,
            'configuration_encrypted' => [
                'url' => 'http://127.0.0.1/hook',
                'default_receiver' => 'noc',
                'allow_private_networks' => true,
            ],
        ]);
        $policy = $this->policy();
        $message = str_repeat('x', 600);
        $this->triggerAction($policy, $destination, ['message_template' => $message]);
        $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:process-actions');

        Http::assertSent(fn ($request) => $request['message'] === $message);
    }

    public function test_saving_a_valid_template_persists(): void
    {
        $this->actingAs($this->admin())
            ->put('/plugin/interface-alert-policy-manager/message-templates', ['templates' => ['recovery' => 'Back up: {{ ifName }} after {{ outage_duration }}']])
            ->assertRedirect();

        self::assertSame('Back up: {{ ifName }} after {{ outage_duration }}', $this->settings->get('template_recovery'));
    }

    public function test_saving_a_template_with_an_unknown_placeholder_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->put('/plugin/interface-alert-policy-manager/message-templates', ['templates' => ['trigger' => 'Down {{ not_a_placeholder }}']])
            ->assertSessionHasErrors('trigger');

        self::assertSame('', $this->settings->get('template_trigger', ''));
    }

    public function test_an_invalid_digest_does_not_partially_save_phase_templates(): void
    {
        $this->settings->put('template_trigger', 'BEFORE');

        $this->actingAs($this->admin())
            ->put('/plugin/interface-alert-policy-manager/message-templates', [
                'templates' => ['trigger' => 'AFTER {{ ifName }}'],
                'digest' => '{{ unknown_digest_value }}',
            ])
            ->assertSessionHasErrors('digest');

        self::assertSame('BEFORE', $this->settings->get('template_trigger'));
    }

    public function test_a_partial_update_does_not_clear_unsubmitted_templates(): void
    {
        $this->settings->put('template_trigger', 'KEEP ME');

        $this->actingAs($this->admin())
            ->put('/plugin/interface-alert-policy-manager/message-templates', [
                'templates' => ['recovery' => 'Recovered {{ ifName }}'],
            ])
            ->assertRedirect();

        self::assertSame('KEEP ME', $this->settings->get('template_trigger'));
    }

    public function test_the_editor_requires_the_manage_settings_ability(): void
    {
        $this->actingAs(User::factory()->create(['enabled' => true]))
            ->put('/plugin/interface-alert-policy-manager/message-templates', ['templates' => ['trigger' => 'x {{ ifName }}']])
            ->assertForbidden();
    }

    public function test_the_editor_page_renders_with_defaults(): void
    {
        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager/message-templates')
            ->assertOk()
            ->assertSee('Message templates')
            ->assertSee('Recovered');
    }
}
