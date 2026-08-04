<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class AuthorizationTest extends IntegrationTestCase
{
    public function test_the_overview_requires_authentication(): void
    {
        $this->get('/plugin/interface-alert-policy-manager')->assertRedirect();
    }

    public function test_a_user_without_abilities_cannot_view_the_plugin(): void
    {
        $this->actingAs(User::factory()->create())
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

        $this->actingAs(User::factory()->create())
            ->post("/plugin/interface-alert-policy-manager/incidents/{$incident->id}/acknowledge")
            ->assertForbidden();

        self::assertSame(IncidentState::Active, $incident->fresh()->state);
    }

    public function test_a_user_without_abilities_cannot_test_a_destination(): void
    {
        Http::fake();
        $destination = $this->smsDestination();

        $this->actingAs(User::factory()->create())
            ->post("/plugin/interface-alert-policy-manager/destinations/{$destination->id}/test", ['receiver' => 'noc'])
            ->assertForbidden();

        Http::assertNothingSent();
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
