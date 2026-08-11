<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\TemplateContextBuilder;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P2-1: the action create/edit form had no help text on any field, number
 * inputs stretched the full page width, and there was no way back to the policy.
 */
class ActionFormQualityTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    private function createForm(): string
    {
        $policy = $this->policy();
        $this->smsDestination();

        return (string) $this->actingAs($this->admin())
            ->get(self::BASE."/policies/{$policy->id}/actions/create")
            ->assertOk()
            ->getContent();
    }

    public function test_every_field_has_help_text(): void
    {
        $body = $this->createForm();

        foreach (['destination', 'phase', 'delay_seconds', 'repeat_seconds', 'maximum_sends', 'sort_order'] as $field) {
            self::assertStringContainsString('id="iapm-action-'.$field.'-help"', $body, "The $field field has no help text.");
            self::assertStringContainsString('aria-describedby="iapm-action-'.$field.'-help"', $body, "The $field field is not linked to its help text.");
        }
    }

    public function test_number_inputs_are_width_constrained(): void
    {
        self::assertStringContainsString('class="form-group iapm-narrow-field"', $this->createForm());
    }

    /** The live seconds-to-human conversion the policy form already had. */
    public function test_second_fields_get_the_live_conversion_hint(): void
    {
        $body = $this->createForm();

        self::assertStringContainsString('iapm-seconds-hint', $body);
        self::assertStringContainsString('class="form-control iapm-seconds"', $body);
    }

    public function test_there_is_a_way_back_to_the_policy(): void
    {
        $policy = $this->policy();
        $this->smsDestination();

        $body = (string) $this->actingAs($this->admin())
            ->get(self::BASE."/policies/{$policy->id}/actions/create")
            ->assertOk()
            ->getContent();

        self::assertStringContainsString('>Cancel</a>', $body);
        self::assertStringContainsString(route('iapm.policies.edit', $policy), $body);
    }

    public function test_the_placeholder_help_and_preview_link_are_present(): void
    {
        $body = $this->createForm();

        self::assertStringContainsString(route('iapm.template-preview'), $body);
        self::assertStringContainsString('data-iapm-chip="hostname"', $body);
        self::assertStringContainsString('data-iapm-chip="outage_duration"', $body);
    }

    /**
     * A chip that inserted a placeholder the renderer rejects would produce a
     * template that fails validation on save, so the two must agree.
     */
    public function test_every_offered_placeholder_is_one_the_renderer_produces(): void
    {
        $port = $this->downPort($this->device());
        $context = app(InterfaceContextService::class)->forPort($port);
        $produced = array_keys(app(TemplateContextBuilder::class)->forPreview($context));

        foreach (TemplateContextBuilder::INTERFACE_PLACEHOLDERS as $placeholder) {
            self::assertContains($placeholder, $produced, "The UI offers {{ $placeholder }} but the renderer never produces it.");
        }
        // And nothing the renderer produces is missing from the list.
        foreach ($produced as $placeholder) {
            self::assertContains($placeholder, TemplateContextBuilder::INTERFACE_PLACEHOLDERS, "The renderer produces $placeholder but the UI does not offer it.");
        }
    }

    public function test_the_digest_placeholder_list_matches_its_sample(): void
    {
        $produced = array_keys(app(TemplateContextBuilder::class)->digestSample());

        sort($produced);
        $offered = TemplateContextBuilder::DIGEST_PLACEHOLDERS;
        sort($offered);

        self::assertSame($offered, $produced);
    }
}
