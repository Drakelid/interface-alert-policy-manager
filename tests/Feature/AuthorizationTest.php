<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;

class AuthorizationTest extends IntegrationTestCase
{
    public function test_the_overview_requires_authentication(): void
    {
        $this->get('/plugin/interface-alert-policy-manager')->assertRedirect();
    }

    public function test_a_user_without_abilities_cannot_view_the_plugin(): void
    {
        $this->actingAs(User::factory()->create(['enabled' => true]))
            ->get('/plugin/interface-alert-policy-manager')
            ->assertForbidden();
    }

    public function test_an_administrator_can_view_the_overview(): void
    {
        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager')
            ->assertOk();
    }

    public function test_a_user_without_abilities_cannot_acknowledge_an_incident(): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));

        $this->actingAs(User::factory()->create(['enabled' => true]))
            ->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/acknowledge")
            ->assertForbidden();

        self::assertSame(IncidentState::Active, $incident->fresh()->state);
    }

    public function test_a_user_without_abilities_cannot_test_a_destination(): void
    {
        Http::fake();
        $destination = $this->smsDestination();

        $this->actingAs(User::factory()->create(['enabled' => true]))
            ->post("/plugin/interface-alert-policy-manager/destinations/{$destination->id}/test", ['receiver' => 'noc'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_view_only_user_cannot_open_destination_configuration_forms(): void
    {
        Permission::findOrCreate('view iapm', 'web');
        $viewer = User::factory()->create(['enabled' => true]);
        $viewer->givePermissionTo('view iapm');
        $destination = $this->smsDestination([
            'url' => 'https://example.test/messages',
            'headers' => ['X-Api-Key' => 'must-not-be-disclosed'],
        ]);

        $this->actingAs($viewer)
            ->get('/plugin/interface-alert-policy-manager/destinations')
            ->assertOk()
            ->assertDontSee('must-not-be-disclosed')
            ->assertDontSee('/destinations/create', false)
            ->assertDontSee("/destinations/{$destination->id}/edit", false);

        $this->actingAs($viewer)
            ->get('/plugin/interface-alert-policy-manager/destinations/create')
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get("/plugin/interface-alert-policy-manager/destinations/{$destination->id}/edit")
            ->assertForbidden();
    }

    /**
     * The policy, action and Policy Test pages all surface receiver values —
     * `Policy::default_receiver` is bound as an input on the policy form,
     * `PolicyAction::receivers_json` on the action form, `Assignment::metadata_json`
     * on the assignment editor, and Policy Test resolves through to the
     * destination's `default_receiver`. All four are the same secret the
     * destination form already withholds, so a view-only user must not reach them.
     */
    public function test_a_view_only_user_cannot_open_policy_or_action_configuration_forms(): void
    {
        $viewer = $this->viewer();
        $policy = $this->policy(['default_receiver' => '+4712345678']);
        $action = $policy->actions()->create([
            'destination_id' => $this->smsDestination()->id,
            'phase' => 'trigger',
            'delay_seconds' => 0,
            'sort_order' => 0,
            'enabled' => true,
            'receivers_json' => ['+4798765432'],
        ]);
        $assignment = $policy->assignments()->create([
            'assignment_type' => 'default',
            'match_mode' => 'any',
            'priority' => 0,
            'enabled' => true,
            'metadata_json' => ['receivers' => ['+4711112222']],
        ]);

        foreach ([
            "/plugin/interface-alert-policy-manager/policies/{$policy->id}/edit",
            "/plugin/interface-alert-policy-manager/policies/{$policy->id}/edit?assignment={$assignment->id}",
            "/plugin/interface-alert-policy-manager/policies/{$policy->id}/edit?assignment=new",
            '/plugin/interface-alert-policy-manager/policies/create',
            "/plugin/interface-alert-policy-manager/policies/{$policy->id}/actions/create",
            "/plugin/interface-alert-policy-manager/actions/{$action->id}/edit",
        ] as $path) {
            $this->actingAs($viewer)->get($path)->assertForbidden();
        }

        // The list stays readable, but without a link into the gated form.
        $this->actingAs($viewer)
            ->get('/plugin/interface-alert-policy-manager/policies')
            ->assertOk()
            ->assertSee($policy->name)
            ->assertDontSee('+4712345678')
            ->assertDontSee("/policies/{$policy->id}/edit", false);
    }

    public function test_policy_test_hides_resolved_receivers_from_a_view_only_user(): void
    {
        $viewer = $this->viewer();
        $port = $this->downPort($this->device());
        $policy = $this->defaultPolicy();
        $policy->actions()->create([
            'destination_id' => $this->smsDestination(['default_receiver' => '+4700000001'])->id,
            'phase' => 'trigger',
            'delay_seconds' => 0,
            'sort_order' => 0,
            'enabled' => true,
        ]);

        $response = $this->actingAs($viewer)
            ->get('/plugin/interface-alert-policy-manager/policy-test?port_id='.$port->port_id)
            ->assertOk();

        // The operational verdict survives; only the value is withheld. Assert on
        // the masking control's own copy — a bare "hidden" also matches
        // `type="hidden"` and `aria-hidden` elsewhere in the layout.
        $response->assertSee($policy->name)
            ->assertSee('viewing it requires permission to manage policies or destinations')
            ->assertDontSee('+4700000001');

        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager/policy-test?port_id='.$port->port_id)
            ->assertOk()
            ->assertSee('+4700000001');
    }

    public function test_the_interface_matrix_hides_write_controls_from_a_view_only_user(): void
    {
        $this->downPort($this->device());

        $this->actingAs($this->viewer())
            ->get('/plugin/interface-alert-policy-manager/interface-matrix')
            ->assertOk()
            ->assertDontSee('/interface-matrix/rebuild-cache', false)
            ->assertDontSee('data-iapm-bulk-button="matrix"', false);

        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager/interface-matrix')
            ->assertOk()
            ->assertSee('/interface-matrix/rebuild-cache', false)
            ->assertSee('data-iapm-bulk-button="matrix"', false);
    }

    private function viewer(): User
    {
        Permission::findOrCreate('view iapm', 'web');
        $viewer = User::factory()->create(['enabled' => true]);
        $viewer->givePermissionTo('view iapm');

        return $viewer;
    }

    public function test_an_administrator_can_acknowledge_and_unacknowledge_an_incident(): void
    {
        $admin = $this->admin();
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));

        $this->actingAs($admin)->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/acknowledge")->assertRedirect();

        $incident->refresh();
        self::assertSame(IncidentState::Acknowledged, $incident->state);
        self::assertSame($admin->user_id, (int) $incident->acknowledged_by);
        self::assertNotNull($incident->acknowledged_at);
        self::assertTrue($incident->events()->where('event_type', 'acknowledged')->exists());

        $this->actingAs($admin)->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/unacknowledge")->assertRedirect();

        $incident->refresh();
        self::assertSame(IncidentState::Active, $incident->state);
        self::assertNull($incident->acknowledged_at);
    }

    public function test_a_recovered_incident_cannot_be_acknowledged(): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()), ['state' => IncidentState::Recovered, 'recovered_at' => now()]);

        $this->actingAs($this->admin())
            ->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/acknowledge")
            ->assertStatus(409);
    }

    #[DataProvider('nonAcknowledgedStates')]
    public function test_unacknowledge_rejects_every_non_acknowledged_state(IncidentState $state): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()), ['state' => $state, 'recovered_at' => $state === IncidentState::Recovered ? now() : null]);
        $this->actingAs($this->admin())->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/unacknowledge")->assertStatus(409);
        self::assertSame($state, $incident->fresh()->state);
    }

    public function test_unacknowledge_restores_the_pre_acknowledgement_state(): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()), ['state' => IncidentState::Suppressed, 'suppression_reason' => 'maintenance']);
        $admin = $this->admin();
        $this->actingAs($admin)->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/acknowledge");
        $this->actingAs($admin)->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/unacknowledge")->assertRedirect();
        self::assertSame(IncidentState::Suppressed, $incident->fresh()->state);
    }

    public static function nonAcknowledgedStates(): array
    {
        return array_map(fn (IncidentState $state) => [$state], [IncidentState::Active, IncidentState::Pending, IncidentState::Suppressed, IncidentState::Recovered]);
    }

    public function test_an_administrator_can_mute_and_unmute_an_incident(): void
    {
        $admin = $this->admin();
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));

        $this->actingAs($admin)
            ->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/mute", ['muted_until' => now()->addHours(2)->toDateTimeString()])
            ->assertRedirect();

        self::assertNotNull($incident->fresh()->muted_until);

        $this->actingAs($admin)->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/unmute")->assertRedirect();

        self::assertNull($incident->fresh()->muted_until);
        self::assertTrue($incident->events()->where('event_type', 'unmuted')->exists());
    }

    public function test_a_mute_in_the_past_is_rejected(): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));

        $this->actingAs($this->admin())
            ->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/mute", ['muted_until' => now()->subHour()->toDateTimeString()])
            ->assertSessionHasErrors('muted_until');
    }

    public function test_the_audit_log_records_administrative_incident_actions(): void
    {
        $admin = $this->admin();
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));

        $this->actingAs($admin)->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/acknowledge");

        $this->assertDatabaseHas('iapm_audit_logs', [
            'user_id' => $admin->user_id,
            'action' => 'acknowledged',
            'object_type' => 'incident',
            'object_id' => $incident->id,
        ]);
    }
}
