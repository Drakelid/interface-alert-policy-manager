<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\DeviceGroup;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class ConfigurationImportIntegrityTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    public function test_round_trip_preserves_exclusions_and_duplicate_child_multiplicity(): void
    {
        $this->actingAs($this->admin());
        $port = $this->downPort($this->device());
        $group = DeviceGroup::factory()->create();
        $policy = $this->policy();
        $policy->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $port->device_id, 'match_mode' => 'exclude']);
        foreach ([20, 10] as $priority) {
            $policy->assignments()->create(['assignment_type' => 'device', 'assignment_reference' => $port->device_id, 'match_mode' => 'any', 'priority' => $priority]);
        }
        foreach (['any', 'exclude'] as $mode) {
            $assignment = $policy->assignments()->create(['assignment_type' => 'device_group', 'match_mode' => $mode]);
            $assignment->deviceGroups()->create(['device_group_id' => $group->id]);
        }
        $destination = $this->smsDestination();
        foreach ([0, 60] as $delay) {
            $this->triggerAction($policy, $destination, ['delay_seconds' => $delay, 'sort_order' => 0]);
        }
        $document = $this->exportPolicy($policy);
        $policy->delete();

        $this->post(self::BASE.'/import', ['document' => json_encode($document), 'action' => 'preview'])
            ->assertOk()->assertViewHas('plan', fn (array $plan) => $plan['counts'] === ['create' => 8, 'update' => 0, 'skip' => 0]);
        $this->applyDocument($document)->assertOk()
            ->assertViewHas('report', fn (array $report) => $report['counts'] === ['create' => 8, 'update' => 0, 'skip' => 0]);

        $restored = Policy::where('name', $document['policies'][0]['name'])->firstOrFail();
        self::assertSame(5, $restored->assignments()->count());
        self::assertSame(2, $restored->actions()->count());
        self::assertEqualsCanonicalizing([0, 60], $restored->actions()->pluck('delay_seconds')->all());
        self::assertEqualsCanonicalizing(['any', 'exclude'], $restored->assignments()->where('assignment_type', 'device_group')->pluck('match_mode')->all());
        self::assertNull(app(PolicyResolver::class)->resolve(app(InterfaceContextService::class)->forPort($port), false)->policy);

        // Updating consumes each original row once instead of overwriting the
        // first matching row repeatedly or adding more copies on each import.
        $this->applyDocument($document, true)->assertOk()
            ->assertViewHas('report', fn (array $report) => $report['counts'] === ['create' => 0, 'update' => 8, 'skip' => 0]);
        self::assertSame(5, $restored->assignments()->count());
        self::assertSame(2, $restored->actions()->count());
        self::assertEqualsCanonicalizing([20, 10], $restored->assignments()->where('assignment_type', 'device')->where('match_mode', 'any')->pluck('priority')->all());
    }

    public function test_imported_foreign_keys_cannot_override_parent_or_named_destination(): void
    {
        $this->actingAs($this->admin());
        $policy = $this->policy();
        $otherPolicy = $this->policy();
        $destination = $this->smsDestination();
        $otherDestination = $this->smsDestination();
        $action = $this->triggerAction($policy, $destination);
        $assignment = $policy->assignments()->create(['assignment_type' => 'default', 'match_mode' => 'any']);
        $document = $this->exportPolicy($policy);
        $document['policies'][0]['actions'][0]['destination_id'] = $otherDestination->id;
        $document['policies'][0]['actions'][0]['policy_id'] = $otherPolicy->id;
        $document['policies'][0]['assignments'][0]['policy_id'] = $otherPolicy->id;

        $this->applyDocument($document, true)->assertOk();

        self::assertSame($policy->id, $action->fresh()->policy_id);
        self::assertSame($destination->id, $action->fresh()->destination_id);
        self::assertSame($policy->id, $assignment->fresh()->policy_id);
        self::assertSame(0, $otherPolicy->actions()->count());
        self::assertSame(0, $otherPolicy->assignments()->count());

        $document['policies'][0]['name'] = 'Imported with foreign keys';
        $this->applyDocument($document)->assertOk();
        $created = Policy::where('name', 'Imported with foreign keys')->firstOrFail();
        self::assertSame($destination->id, $created->actions()->sole()->destination_id);
        self::assertSame(1, $created->assignments()->count());
    }

    public function test_no_op_regex_update_at_the_cap_does_not_count_assignments_twice(): void
    {
        $this->actingAs($this->admin());
        config(['iapm.resolver.max_regex_assignments' => 2]);
        $policy = $this->policy();
        foreach (['/^first$/', '/^second$/'] as $pattern) {
            $policy->assignments()->create(['assignment_type' => 'ifname_regex', 'match_expression' => $pattern, 'match_mode' => 'any']);
        }

        $this->applyDocument($this->exportPolicy($policy), true)->assertOk();

        self::assertSame(2, $policy->assignments()->count());
    }

    public function test_regex_projection_accounts_for_disabled_policies_and_local_only_rows(): void
    {
        $this->actingAs($this->admin());
        config(['iapm.resolver.max_regex_assignments' => 1]);
        $active = $this->policy();
        $active->assignments()->create(['assignment_type' => 'ifname_regex', 'match_expression' => '/^active$/', 'match_mode' => 'any']);
        $disabled = $this->policy(['enabled' => false]);
        $disabled->assignments()->create(['assignment_type' => 'ifname_regex', 'match_expression' => '/^disabled$/', 'match_mode' => 'any']);
        $document = $this->exportPolicy($disabled);
        $document['policies'][0]['name'] = 'Imported disabled regex';

        $this->applyDocument($document)->assertOk();

        $document = $this->exportPolicy($disabled);
        $document['policies'][0]['enabled'] = true;
        $document['policies'][0]['assignments'] = [];
        $this->applyDocument($document, true)->assertRedirect()->assertSessionHasErrors('policies');
        self::assertFalse($disabled->fresh()->enabled);
    }

    public function test_regex_projection_rejects_a_real_addition_beyond_the_cap(): void
    {
        $this->actingAs($this->admin());
        config(['iapm.resolver.max_regex_assignments' => 1]);
        $policy = $this->policy();
        $policy->assignments()->create(['assignment_type' => 'ifname_regex', 'match_expression' => '/^first$/', 'match_mode' => 'any']);
        $document = $this->exportPolicy($policy);
        $document['policies'][0]['assignments'][] = array_replace($document['policies'][0]['assignments'][0], ['match_expression' => '/^second$/']);

        $this->applyDocument($document, true)->assertRedirect()->assertSessionHasErrors('policies');

        self::assertSame(1, $policy->assignments()->count());
    }

    private function exportPolicy(Policy $policy): array
    {
        $document = json_decode($this->get(self::BASE.'/export')->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $document['policies'] = array_values(array_filter($document['policies'], fn (array $row) => $row['name'] === $policy->name));

        return $document;
    }

    private function applyDocument(array $document, bool $updateExisting = false)
    {
        return $this->post(self::BASE.'/import', [
            'document' => json_encode($document, JSON_THROW_ON_ERROR),
            'action' => 'apply',
            'update_existing' => $updateExisting ? '1' : '0',
        ]);
    }
}
