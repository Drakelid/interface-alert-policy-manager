<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P2-4: Save sat at the bottom of the left column, and the delete confirmation
 * read only "Delete this policy?" — saying nothing about the active incidents
 * or whether the migration selection had been applied. On a single-policy
 * install the migrate dropdown had no options at all, offering an action that
 * could never succeed.
 */
class PolicyDeleteFlowTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    public function test_save_is_a_full_width_footer_covering_both_columns(): void
    {
        $policy = $this->policy();

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/policies/{$policy->id}/edit")->assertOk()->getContent();

        self::assertStringContainsString('iapm-form-footer', $body);
        self::assertStringContainsString('Saves every field on this page, in both columns.', $body);
    }

    public function test_the_confirmation_names_what_is_deleted_when_there_are_no_incidents(): void
    {
        $policy = $this->policy();
        $this->triggerAction($policy, $this->smsDestination());
        $policy->assignments()->create(['assignment_type' => 'default', 'match_mode' => 'any', 'priority' => 0, 'enabled' => true]);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/policies/{$policy->id}/edit")->assertOk()->getContent();

        self::assertStringContainsString('1 notification action(s) and 1 assignment(s) are deleted with it', $body);
        self::assertStringNotContainsString("confirm('Delete this policy?')", $body);
    }

    public function test_the_confirmation_states_where_active_incidents_go(): void
    {
        $policy = $this->policy(['name' => 'Has incidents']);
        $this->policy(['name' => 'Somewhere else']);
        $this->incident($policy, $this->downPort($this->device()));

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/policies/{$policy->id}/edit")->assertOk()->getContent();

        self::assertStringContainsString('move its 1 active incident(s) to the policy selected above', $body);
        self::assertStringContainsString('id="iapm-migrate-to"', $body);
    }

    /**
     * The observed case: one policy, one active incident. The dropdown had no
     * options, so the form could never be submitted successfully.
     */
    public function test_a_single_policy_install_explains_why_delete_is_unavailable(): void
    {
        $policy = $this->policy(['name' => 'The only policy']);
        $this->incident($policy, $this->downPort($this->device()));

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/policies/{$policy->id}/edit")->assertOk()->getContent();

        self::assertStringContainsString('there is no other policy to move them to', $body);
        self::assertStringContainsString('<button class="btn btn-danger" disabled>', $body);
        self::assertStringNotContainsString('id="iapm-migrate-to"', $body);
    }

    /** A policy with no incidents is deletable even when it is the only one. */
    public function test_the_only_policy_can_still_be_deleted_when_it_has_no_incidents(): void
    {
        $policy = $this->policy(['name' => 'Lonely but idle']);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/policies/{$policy->id}/edit")->assertOk()->getContent();
        self::assertStringNotContainsString('<button class="btn btn-danger" disabled>', $body);

        $this->actingAs($this->admin())->delete(self::BASE."/policies/{$policy->id}")->assertRedirect(route('iapm.policies.index'));
        self::assertNull(Policy::find($policy->id));
    }

    /** The migration the confirmation promises must actually happen. */
    public function test_deleting_with_a_migration_target_moves_the_incidents(): void
    {
        $policy = $this->policy(['name' => 'Retiring']);
        $target = $this->policy(['name' => 'Successor']);
        $incident = $this->incident($policy, $this->downPort($this->device()));

        $this->actingAs($this->admin())
            ->delete(self::BASE."/policies/{$policy->id}", ['migrate_to' => $target->id])
            ->assertRedirect(route('iapm.policies.index'));

        self::assertNull(Policy::find($policy->id));
        self::assertSame($target->id, $incident->fresh()->policy_id);
    }

    public function test_deleting_without_a_migration_target_is_refused(): void
    {
        $policy = $this->policy();
        $this->policy();
        $this->incident($policy, $this->downPort($this->device()));

        $this->actingAs($this->admin())
            ->delete(self::BASE."/policies/{$policy->id}")
            ->assertSessionHasErrors();

        self::assertNotNull(Policy::find($policy->id));
    }
}
