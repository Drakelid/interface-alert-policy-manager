<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class PolicyAndDestinationAdminTest extends IntegrationTestCase
{
    public function test_a_policy_with_active_incidents_cannot_be_deleted_without_a_migration_target(): void
    {
        $policy = $this->policy();
        $this->incident($policy, $this->downPort($this->device()));

        $this->actingAs($this->admin())
            ->delete("/plugin/interface-alert-policy-manager/policies/{$policy->id}")
            ->assertSessionHasErrors();

        self::assertNotNull($policy->fresh());
    }

    public function test_deleting_a_policy_migrates_its_active_incidents(): void
    {
        $policy = $this->policy(['name' => 'Retiring']);
        $target = $this->policy(['name' => 'Successor']);
        $incident = $this->incident($policy, $this->downPort($this->device()));

        $this->actingAs($this->admin())
            ->delete("/plugin/interface-alert-policy-manager/policies/{$policy->id}", ['migrate_to' => $target->id])
            ->assertRedirect();

        self::assertNull(Policy::find($policy->id));
        $incident->refresh();
        self::assertSame($target->id, $incident->policy_id);
        self::assertTrue($incident->events()->where('event_type', 'policy_changed')->exists());
    }

    public function test_a_policy_cannot_migrate_incidents_to_itself(): void
    {
        $policy = $this->policy();
        $this->incident($policy, $this->downPort($this->device()));

        $this->actingAs($this->admin())
            ->delete("/plugin/interface-alert-policy-manager/policies/{$policy->id}", ['migrate_to' => $policy->id])
            ->assertSessionHasErrors('migrate_to');
    }

    public function test_a_policy_without_incidents_deletes_directly(): void
    {
        $policy = $this->policy();

        $this->actingAs($this->admin())
            ->delete("/plugin/interface-alert-policy-manager/policies/{$policy->id}")
            ->assertRedirect();

        self::assertNull(Policy::find($policy->id));
    }

    public function test_a_generic_webhook_destination_can_be_created_without_a_password(): void
    {
        $this->actingAs($this->admin())
            ->post('/plugin/interface-alert-policy-manager/destinations', [
                'name' => 'Ops webhook',
                'type' => 'generic_webhook',
                'enabled' => '1',
                'url' => 'https://hooks.example.com/notify',
                'bearer_token' => 'a-token',
                'mode' => 'json',
                'connect_timeout' => 5,
                'timeout' => 15,
                'retry_count' => 1,
                'retry_delay_ms' => 500,
                'verify_tls' => '1',
            ])
            ->assertRedirect();

        $destination = Destination::sole();
        self::assertSame('generic_webhook', $destination->type);
        self::assertSame('a-token', $destination->configuration_encrypted['bearer_token']);
    }

    public function test_an_sms_gateway_destination_still_requires_a_password_on_create(): void
    {
        $this->actingAs($this->admin())
            ->post('/plugin/interface-alert-policy-manager/destinations', [
                'name' => 'SMS gateway',
                'type' => 'sms_gateway',
                'enabled' => '1',
                'url' => 'https://sms.example.com/api/v10/messages/send',
                'username' => 'gw',
                'mode' => 'json',
                'connect_timeout' => 5,
                'timeout' => 15,
                'retry_count' => 1,
                'retry_delay_ms' => 500,
                'verify_tls' => '1',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_editing_a_destination_leaves_the_password_untouched_when_left_blank(): void
    {
        $destination = $this->smsDestination();
        $originalPassword = $destination->configuration_encrypted['password'];

        $this->actingAs($this->admin())
            ->put("/plugin/interface-alert-policy-manager/destinations/{$destination->id}", [
                'name' => $destination->name,
                'type' => 'sms_gateway',
                'enabled' => '1',
                'url' => $destination->configuration_encrypted['url'],
                'username' => 'gateway-user',
                'password' => '',
                'default_receiver' => 'noc',
                'mode' => 'json',
                'connect_timeout' => 5,
                'timeout' => 15,
                'retry_count' => 0,
                'retry_delay_ms' => 0,
                'verify_tls' => '1',
                'allow_private_networks' => '1',
            ])
            ->assertRedirect();

        self::assertSame($originalPassword, $destination->fresh()->configuration_encrypted['password']);
    }

    public function test_the_overview_reports_active_incidents_split_by_severity(): void
    {
        $critical = $this->policy(['severity' => 'critical']);
        $warning = $this->policy(['severity' => 'warning']);
        $this->incident($critical, $this->downPort($this->device()), ['severity' => 'critical', 'state' => IncidentState::Active]);
        $this->incident($warning, $this->downPort($this->device()), ['severity' => 'warning', 'state' => IncidentState::Active]);

        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager')
            ->assertOk()
            ->assertSee('Active critical')
            ->assertSee('Active warning');
    }
}
