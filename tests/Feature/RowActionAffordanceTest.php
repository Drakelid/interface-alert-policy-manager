<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P1-4: table cells named entities without linking to them, and the only edit
 * affordance on the Policies and Destinations lists was the row name, which
 * carried no visual signal.
 */
class RowActionAffordanceTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    public function test_the_policies_list_has_an_explicit_edit_control(): void
    {
        $policy = $this->policy();

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/policies')->assertOk()->getContent();

        self::assertMatchesRegularExpression(
            '#<a class="btn[^"]*" href="'.preg_quote(route('iapm.policies.edit', $policy), '#').'">.*?Edit</a>#s',
            $body,
            'The policies list has no explicit Edit button.'
        );
    }

    public function test_the_destinations_list_has_an_explicit_edit_control(): void
    {
        $destination = $this->smsDestination();

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/destinations')->assertOk()->getContent();

        self::assertMatchesRegularExpression(
            '#<a class="btn[^"]*" href="'.preg_quote(route('iapm.destinations.edit', $destination), '#').'">.*?Edit</a>#s',
            $body,
            'The destinations list has no explicit Edit button.'
        );
    }

    public function test_the_policy_actions_table_offers_edit_and_delete_per_row(): void
    {
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/policies/{$policy->id}/edit")->assertOk()->getContent();

        self::assertStringContainsString(route('iapm.actions.edit', $action), $body);
        self::assertStringContainsString(route('iapm.actions.destroy', $action), $body);
        // P2-4/P3-6: the confirmation must say what is being destroyed.
        self::assertStringContainsString('data-iapm-confirm', $body);
    }

    /** Deleting from the policy page must actually work, not just render. */
    public function test_an_action_can_be_deleted_from_the_policy_page(): void
    {
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination());

        $this->actingAs($this->admin())
            ->delete(self::BASE."/actions/{$action->id}")
            ->assertRedirect(route('iapm.policies.edit', $policy));

        self::assertNull(PolicyAction::find($action->id));
    }

    /** P1-4: the action's destination cell named a destination without linking it. */
    public function test_the_action_row_links_its_destination(): void
    {
        $policy = $this->policy();
        $destination = $this->smsDestination();
        $this->triggerAction($policy, $destination);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/policies/{$policy->id}/edit")->assertOk()->getContent();

        self::assertStringContainsString(route('iapm.destinations.edit', $destination), $body);
    }
}
