<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P2-3: Username, Password and Bearer token rendered for both destination
 * types with the rules explained only in prose, the create form carried
 * edit-form copy, and the "JSON object" header field was pre-filled with [].
 */
class DestinationFormTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    public function test_fields_are_tagged_with_the_types_they_belong_to(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/destinations/create')->assertOk()->getContent();

        // Bearer token is webhook-only; default receiver is SMS-only.
        self::assertMatchesRegularExpression('#iapm-dest-field" data-types="generic_webhook">\s*<label for="iapm-dest-bearer"#', $body);
        self::assertMatchesRegularExpression('#iapm-dest-field" data-types="sms_gateway">\s*<label for="iapm-dest-receiver"#', $body);
        // Basic credentials are valid for both.
        self::assertMatchesRegularExpression('#iapm-dest-field" data-types="sms_gateway,generic_webhook">\s*<label for="iapm-dest-username"#', $body);
    }

    /** The create page must not tell the operator about a token it has not stored. */
    public function test_the_create_form_does_not_use_edit_form_copy(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/destinations/create')->assertOk()->getContent();

        self::assertStringNotContainsString('Leave blank to keep the stored token', $body);
        self::assertStringNotContainsString('Leave blank to keep the stored password', $body);
    }

    public function test_the_edit_form_does_use_edit_form_copy(): void
    {
        $destination = $this->smsDestination();

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/destinations/{$destination->id}/edit")->assertOk()->getContent();

        self::assertStringContainsString('Leave blank to keep the stored password', $body);
    }

    /** P2-3: the textarea was pre-filled with [], an array, under an "object" label. */
    public function test_the_custom_headers_field_defaults_to_an_empty_object(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/destinations/create')->assertOk()->getContent();

        self::assertStringContainsString('id="iapm-dest-headers" class="form-control" rows="2" style="font-family:monospace;" aria-describedby="iapm-dest-headers-help">{}</textarea>', $body);
    }

    public function test_an_empty_header_object_is_accepted(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/destinations', [
            'name' => 'Webhook', 'type' => 'generic_webhook', 'url' => 'http://127.0.0.1:9000/hook', 'allow_private_networks' => '1',
            'mode' => 'json', 'connect_timeout' => 5, 'timeout' => 15, 'retry_count' => 0, 'retry_delay_ms' => 0,
            'headers_json' => '{}', 'enabled' => '1', 'verify_tls' => '1',
        ])->assertRedirect();

        self::assertSame([], Destination::where('name', 'Webhook')->firstOrFail()->configuration_encrypted['headers']);
    }

    /**
     * Hidden fields are disabled, so a webhook submits no bearer token when the
     * form is showing SMS fields. An existing token must survive that.
     */
    public function test_omitting_the_bearer_token_keeps_the_stored_one(): void
    {
        $destination = Destination::create([
            'name' => 'Hook', 'type' => 'generic_webhook', 'enabled' => true,
            'configuration_encrypted' => ['url' => 'http://127.0.0.1:9000/hook', 'allow_private_networks' => '1', 'bearer_token' => 'stored-token', 'mode' => 'json', 'verify_tls' => true, 'retry_count' => 0, 'retry_delay_ms' => 0, 'connect_timeout' => 5, 'timeout' => 15],
        ]);

        $this->actingAs($this->admin())->put(self::BASE."/destinations/{$destination->id}", [
            'name' => 'Hook renamed', 'type' => 'generic_webhook', 'url' => 'http://127.0.0.1:9000/hook', 'allow_private_networks' => '1',
            'mode' => 'json', 'connect_timeout' => 5, 'timeout' => 15, 'retry_count' => 0, 'retry_delay_ms' => 0,
            'headers_json' => '{}', 'enabled' => '1', 'verify_tls' => '1',
        ])->assertRedirect();

        self::assertSame('stored-token', $destination->fresh()->configuration_encrypted['bearer_token']);
    }

    public function test_an_sms_gateway_still_requires_a_password_on_create(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/destinations', [
            'name' => 'No password', 'type' => 'sms_gateway', 'url' => 'http://127.0.0.1:9000/send', 'allow_private_networks' => '1',
            'mode' => 'json', 'connect_timeout' => 5, 'timeout' => 15, 'retry_count' => 0, 'retry_delay_ms' => 0,
            'headers_json' => '{}', 'enabled' => '1', 'verify_tls' => '1',
        ])->assertSessionHasErrors('password');
    }

    /** The delete confirmation should say what is lost, not just "cannot be undone". */
    public function test_the_delete_confirmation_names_the_consequences(): void
    {
        $destination = $this->smsDestination();

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/destinations/{$destination->id}/edit")->assertOk()->getContent();

        self::assertStringContainsString('data-iapm-confirm', $body);
        self::assertStringContainsString('stored credentials are erased', $body);
    }
}
