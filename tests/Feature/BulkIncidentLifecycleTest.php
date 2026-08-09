<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class BulkIncidentLifecycleTest extends IntegrationTestCase
{
    public function test_bulk_acknowledgement_preserves_each_prior_state_and_is_idempotent(): void
    {
        $admin = $this->admin();
        $policy = $this->policy();
        $device = $this->device();
        $pending = $this->incident($policy, $this->downPort($device), [
            'state' => IncidentState::Pending,
            'triggered_at' => null,
        ]);
        $suppressed = $this->incident($policy, $this->downPort($device), [
            'state' => IncidentState::Suppressed,
            'triggered_at' => null,
            'suppression_reason' => 'maintenance',
        ]);
        $payload = [
            'incident_ids' => [$pending->id, $suppressed->id],
            'operation' => 'acknowledge',
        ];

        $this->actingAs($admin)->post('/plugin/interface-alert-policy-manager/incidents/bulk', $payload)->assertRedirect();
        $this->actingAs($admin)->post('/plugin/interface-alert-policy-manager/incidents/bulk', $payload)->assertRedirect();

        self::assertSame(IncidentState::Acknowledged, $pending->fresh()->state);
        self::assertSame(IncidentState::Pending->value, $pending->fresh()->pre_acknowledgement_state);
        self::assertSame(IncidentState::Suppressed->value, $suppressed->fresh()->pre_acknowledgement_state);
        self::assertSame(1, $pending->events()->where('event_type', 'acknowledged')->count());
        self::assertSame(1, $suppressed->events()->where('event_type', 'acknowledged')->count());

        $this->actingAs($admin)->post("/plugin/interface-alert-policy-manager/incidents/{$pending->id}/unacknowledge")->assertRedirect();
        $this->actingAs($admin)->post("/plugin/interface-alert-policy-manager/incidents/{$suppressed->id}/unacknowledge")->assertRedirect();
        self::assertSame(IncidentState::Pending, $pending->fresh()->state);
        self::assertSame(IncidentState::Suppressed, $suppressed->fresh()->state);
    }
}
