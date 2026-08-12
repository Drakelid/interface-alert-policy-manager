<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class BulkDeleteTest extends IntegrationTestCase
{
    private function assignment(Policy $policy, array $attributes = []): Assignment
    {
        return $policy->assignments()->create(array_merge(['assignment_type' => 'device', 'assignment_reference' => (string) $this->faker->unique()->numberBetween(1, 99999), 'match_mode' => 'any', 'priority' => 0, 'enabled' => true], $attributes));
    }

    public function test_assignments_are_bulk_deleted(): void
    {
        $policy = $this->policy();
        $a = $this->assignment($policy);
        $b = $this->assignment($policy);
        $keep = $this->assignment($policy);

        $this->actingAs($this->admin())
            ->delete('/plugin/interface-alert-policy-manager/assignments-bulk', ['ids' => [$a->id, $b->id]])
            ->assertRedirect();

        self::assertNull(Assignment::find($a->id));
        self::assertNull(Assignment::find($b->id));
        self::assertNotNull(Assignment::find($keep->id));
    }

    public function test_bulk_delete_requires_the_manage_ability(): void
    {
        $policy = $this->policy();
        $a = $this->assignment($policy);

        $this->actingAs(User::factory()->create(['enabled' => true]))
            ->delete('/plugin/interface-alert-policy-manager/assignments-bulk', ['ids' => [$a->id]])
            ->assertForbidden();

        self::assertNotNull(Assignment::find($a->id));
    }

    public function test_bulk_delete_validates_the_id_list(): void
    {
        $this->actingAs($this->admin())
            ->delete('/plugin/interface-alert-policy-manager/assignments-bulk', ['ids' => []])
            ->assertSessionHasErrors('ids');
    }

    public function test_policies_referenced_by_assignments_or_incidents_are_skipped(): void
    {
        $referencedByAssignment = $this->policy(['name' => 'Has assignment']);
        $this->assignment($referencedByAssignment);

        $referencedByIncident = $this->policy(['name' => 'Has incident']);
        $this->incident($referencedByIncident, $this->downPort($this->device()));

        $free = $this->policy(['name' => 'Free']);

        $this->actingAs($this->admin())
            ->delete('/plugin/interface-alert-policy-manager/policies-bulk', ['ids' => [$referencedByAssignment->id, $referencedByIncident->id, $free->id]])
            ->assertRedirect();

        self::assertNotNull(Policy::find($referencedByAssignment->id), 'Referenced by an assignment.');
        self::assertNotNull(Policy::find($referencedByIncident->id), 'Referenced by an active incident.');
        self::assertNull(Policy::find($free->id), 'Unreferenced policy is deleted.');
    }

    public function test_destinations_used_by_actions_are_skipped(): void
    {
        $used = $this->smsDestination(['name' => 'Used']);
        $policy = $this->policy();
        $this->triggerAction($policy, $used);
        $free = $this->smsDestination(['name' => 'Free']);

        $this->actingAs($this->admin())
            ->delete('/plugin/interface-alert-policy-manager/destinations-bulk', ['ids' => [$used->id, $free->id]])
            ->assertRedirect();

        self::assertNotNull(Destination::find($used->id));
        self::assertNull(Destination::find($free->id));
    }

    public function test_deleting_an_assignment_clears_the_policy_cache(): void
    {
        $policy = $this->defaultPolicy();
        $port = $this->downPort($this->device());
        // Warm the cache by resolving the port.
        app(PolicyResolver::class)
            ->resolve(app(InterfaceContextService::class)->forPort($port));
        self::assertSame(1, DB::table('iapm_interface_policy_cache')->count());

        $this->actingAs($this->admin())
            ->delete('/plugin/interface-alert-policy-manager/assignments-bulk', ['ids' => $policy->assignments()->pluck('id')->all()]);

        self::assertSame(0, DB::table('iapm_interface_policy_cache')->count(), 'Model delete events fired, clearing the cache.');
    }
}
