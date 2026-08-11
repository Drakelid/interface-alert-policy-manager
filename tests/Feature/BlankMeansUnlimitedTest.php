<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P2-2: the policy form said blank meant unlimited for "Maximum repeats" while
 * the action form defaulted "Maximum sends" to 0 and said nothing at all.
 *
 * One convention now applies to both: blank = unlimited (or inherit), 0 = none.
 */
class BlankMeansUnlimitedTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    /**
     * The live bug behind the inconsistency: the create form rendered 0 into
     * fields validated as min:60 and min:1, so an unedited form could not be
     * submitted at all.
     */
    public function test_the_action_create_form_can_be_submitted_unedited(): void
    {
        $policy = $this->policy();
        $destination = $this->smsDestination();

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/policies/{$policy->id}/actions/create")->assertOk()->getContent();
        self::assertStringContainsString('id="iapm-action-repeat_seconds" name="repeat_seconds" class="form-control iapm-seconds" value=""', $body);
        self::assertStringContainsString('id="iapm-action-maximum_sends" name="maximum_sends" class="form-control" value=""', $body);

        // Exactly what the unedited form posts.
        $this->actingAs($this->admin())->post(self::BASE."/policies/{$policy->id}/actions", [
            'destination_id' => $destination->id,
            'phase' => 'trigger',
            'delay_seconds' => '0',
            'repeat_seconds' => '',
            'maximum_sends' => '',
            'sort_order' => '0',
            'enabled' => '1',
        ])->assertRedirect(route('iapm.policies.edit', $policy));

        $action = PolicyAction::firstOrFail();
        self::assertNull($action->repeat_seconds, 'Blank repeat should store as unlimited/inherit, not 0.');
        self::assertNull($action->maximum_sends);
    }

    public function test_blank_maximum_repeats_stores_as_unlimited(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/policies', [
            'name' => 'Unlimited repeats', 'severity' => 'critical', 'priority' => 0,
            'trigger_after_seconds' => 0, 'failed_poll_count' => 1, 'recovery_after_seconds' => 0,
            'repeat_seconds' => '300', 'maximum_repeats' => '', 'enabled' => '1', 'notifications_enabled' => '1',
        ])->assertRedirect();

        self::assertNull(Policy::where('name', 'Unlimited repeats')->firstOrFail()->maximum_repeats);
    }

    /** 0 must keep meaning "none", which is the other half of the convention. */
    public function test_zero_maximum_repeats_is_accepted_and_means_none(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/policies', [
            'name' => 'No repeats', 'severity' => 'critical', 'priority' => 0,
            'trigger_after_seconds' => 0, 'failed_poll_count' => 1, 'recovery_after_seconds' => 0,
            'repeat_seconds' => '300', 'maximum_repeats' => '0', 'enabled' => '1', 'notifications_enabled' => '1',
        ])->assertRedirect();

        self::assertSame(0, Policy::where('name', 'No repeats')->firstOrFail()->maximum_repeats);
    }

    /** 0 sends would mean "never send", which the field cannot express; reject it. */
    public function test_zero_maximum_sends_is_rejected_rather_than_silently_meaning_unlimited(): void
    {
        $policy = $this->policy();

        $this->actingAs($this->admin())->post(self::BASE."/policies/{$policy->id}/actions", [
            'destination_id' => $this->smsDestination()->id, 'phase' => 'trigger',
            'delay_seconds' => '0', 'maximum_sends' => '0', 'sort_order' => '0', 'enabled' => '1',
        ])->assertSessionHasErrors('maximum_sends');
    }

    /** Both forms must actually tell the operator what blank does. */
    public function test_both_forms_state_the_convention(): void
    {
        $policy = $this->policy();
        $admin = $this->admin();

        $policyForm = (string) $this->actingAs($admin)->get(self::BASE."/policies/{$policy->id}/edit")->assertOk()->getContent();
        self::assertStringContainsString('Blank = unlimited', $policyForm);

        $actionForm = (string) $this->actingAs($admin)->get(self::BASE."/policies/{$policy->id}/actions/create")->assertOk()->getContent();
        self::assertStringContainsString('Blank = unlimited', $actionForm);
        self::assertStringContainsString('Blank = inherit', $actionForm);
    }

    /** An existing action keeps its stored values when reopened. */
    public function test_an_existing_action_round_trips_its_values(): void
    {
        $policy = $this->policy();
        $action = $this->triggerAction($policy, $this->smsDestination(), ['repeat_seconds' => 600, 'maximum_sends' => 3, 'delay_seconds' => 120]);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/actions/{$action->id}/edit")->assertOk()->getContent();

        self::assertStringContainsString('name="repeat_seconds" class="form-control iapm-seconds" value="600"', $body);
        self::assertStringContainsString('name="maximum_sends" class="form-control" value="3"', $body);
        self::assertStringContainsString('name="delay_seconds" class="form-control iapm-seconds" value="120"', $body);
    }
}
