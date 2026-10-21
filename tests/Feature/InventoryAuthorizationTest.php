<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Facades\LibrenmsConfig;
use App\Models\DeviceGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Assignment;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Outage;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;
use Spatie\Permission\Models\Permission;

class InventoryAuthorizationTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    public function test_an_iapm_viewer_without_core_inventory_grants_sees_no_inventory(): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device(['hostname' => 'ungranted-core-device'])));
        $this->actingAs($this->userWith())->getJson(self::BASE.'/lookup/devices?q=ungranted')->assertOk()->assertExactJson([]);
        $this->get(self::BASE.'/interface-matrix')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 0);
        $this->get(self::BASE)->assertOk()->assertViewHas('metrics', fn ($metrics) => $metrics['active_critical'] === 0);
        $this->get(self::BASE.'/incidents/'.$incident->id)->assertNotFound();
    }

    public function test_an_itemized_port_grant_scopes_lookup_matrix_incidents_metrics_and_exports(): void
    {
        $viewer = $this->userWith(['view iapm audit logs']);
        $device = $this->device(['hostname' => 'acl-visible-parent']);
        $allowed = $this->downPort($device, ['ifAlias' => 'acl-allowed-interface']);
        $hidden = $this->downPort($device, ['ifAlias' => 'acl-hidden-sibling']);
        $other = $this->downPort($this->device(['hostname' => 'acl-hidden-device']), ['ifAlias' => 'acl-hidden-other']);
        $allowedGroup = DeviceGroup::factory()->create(['name' => 'Visible group']);
        $hiddenGroup = DeviceGroup::factory()->create(['name' => 'Hidden group']);
        DeviceGroup::factory()->create(['name' => 'Empty hidden group']);
        $device->groups()->attach($allowedGroup->id);
        DB::table('device_group_device')->insert(['device_group_id' => $hiddenGroup->id, 'device_id' => $other->device_id]);
        DB::table('ports_perms')->insert(['user_id' => $viewer->user_id, 'port_id' => $allowed->port_id]);
        $policy = $this->policy();
        $allowedIncident = $this->incident($policy, $allowed);
        $hiddenIncident = $this->incident($policy, $hidden);
        $this->incident($policy, $other);
        foreach ([$allowedIncident, $hiddenIncident] as $incident) {
            Outage::create(['incident_id' => $incident->id, 'episode_uuid' => $incident->episode_uuid, 'device_id' => $incident->device_id, 'port_id' => $incident->port_id, 'policy_id' => $policy->id, 'severity' => 'critical', 'started_at' => now()->subHour(), 'recovered_at' => now(), 'duration_seconds' => 60]);
        }
        $destination = $this->smsDestination();
        foreach ([$allowedIncident, $hiddenIncident] as $incident) {
            DeliveryLog::create(['incident_id' => $incident->id, 'destination_id' => $destination->id, 'phase' => 'trigger', 'status' => 'failed', 'error_message' => 'delivery-for-'.$incident->port_id]);
        }

        $this->actingAs($viewer)->getJson(self::BASE.'/lookup/ports?q=acl-')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', (int) $allowed->port_id);
        $this->getJson(self::BASE.'/lookup/devices?q=acl-hidden')->assertOk()->assertExactJson([]);
        $this->getJson(self::BASE.'/lookup/devices?q=acl-visible')->assertOk()->assertJsonPath('0.id', (int) $device->device_id);
        $this->get(self::BASE.'/interface-matrix')->assertOk()->assertSee('acl-allowed-interface')->assertDontSee('acl-hidden-sibling')->assertDontSee('acl-hidden-other')
            ->assertViewHas('deviceGroups', fn ($groups) => $groups->modelKeys() === [$allowedGroup->id]);
        $csv = $this->get(self::BASE.'/interface-matrix/export')->assertOk()->streamedContent();
        self::assertStringContainsString('acl-allowed-interface', $csv);
        self::assertStringNotContainsString('acl-hidden-', $csv);
        $this->get(self::BASE.'/incidents')->assertOk()->assertViewHas('incidents', fn ($rows) => $rows->total() === 1);
        $this->get(self::BASE)->assertOk()->assertViewHas('metrics', fn ($metrics) => $metrics['active_critical'] === 1 && $metrics['failed_deliveries'] === 1);
        $this->get(self::BASE.'/stats')->assertOk()->assertViewHas('metrics', fn ($metrics) => $metrics['outages'] === 1);
        $this->get(self::BASE.'/comparison-report')->assertOk()->assertViewHas('metrics', fn ($metrics) => $metrics['outage_episodes'] === 1 && $metrics['processing_errors'] === 1);
        $deliveries = $this->get(self::BASE.'/delivery-log/export')->assertOk()->streamedContent();
        self::assertStringContainsString('delivery-for-'.$allowed->port_id, $deliveries);
        self::assertStringNotContainsString('delivery-for-'.$hidden->port_id, $deliveries);
    }

    public function test_port_grants_allow_own_incident_actions_and_reject_cross_device_or_mixed_bulk_writes(): void
    {
        $viewer = $this->userWith(['acknowledge iapm incidents', 'mute iapm incidents', 'manage iapm policies', 'manage iapm assignments']);
        $allowed = $this->downPort($this->device());
        $hidden = $this->downPort($this->device());
        DB::table('ports_perms')->insert(['user_id' => $viewer->user_id, 'port_id' => $allowed->port_id]);
        $policy = $this->policy();
        $own = $this->incident($policy, $allowed);
        $foreign = $this->incident($policy, $hidden);
        $this->actingAs($viewer)->get(self::BASE.'/incidents/'.$own->id)->assertOk();
        foreach ([self::BASE.'/incidents/'.$foreign->id, self::BASE.'/policy-test?port_id='.$hidden->port_id, self::BASE.'/tools/simulate?port_id='.$hidden->port_id, self::BASE.'/template-preview?port_id='.$hidden->port_id] as $path) {
            $this->get($path)->assertNotFound();
        }
        foreach (['acknowledge', 'unacknowledge', 'mute', 'unmute', 'reconcile', 'resend'] as $operation) {
            $this->post(self::BASE.'/incidents/'.$foreign->id.'/'.$operation, ['muted_until' => now()->addHour()->toDateTimeString()])->assertNotFound();
        }
        $this->post(self::BASE.'/incidents/bulk', ['incident_ids' => [$own->id, $foreign->id], 'operation' => 'acknowledge'])->assertNotFound();
        $this->post(self::BASE.'/interface-matrix/bulk', ['port_ids' => [$allowed->port_id, $hidden->port_id], 'operation' => 'assign', 'policy_id' => $policy->id])->assertNotFound();
        foreach ([' '.$allowed->port_id.' ', '+'.$allowed->port_id] as $formattedId) {
            $this->post(self::BASE.'/interface-matrix/bulk', ['port_ids' => [$formattedId, $hidden->port_id], 'operation' => 'assign', 'policy_id' => $policy->id])->assertNotFound();
            $this->post(self::BASE.'/incidents/bulk', ['incident_ids' => [' '.$own->id.' ', $foreign->id], 'operation' => 'acknowledge'])->assertNotFound();
        }
        self::assertSame(IncidentState::Active, $own->fresh()->state);
        self::assertSame(IncidentState::Active, $foreign->fresh()->state);
        self::assertSame(0, Assignment::count());
        $this->post(self::BASE.'/incidents/'.$own->id.'/acknowledge')->assertRedirect();
        self::assertSame(IncidentState::Acknowledged, $own->fresh()->state);
        $this->get(self::BASE.'/policy-test?port_id='.$allowed->port_id)->assertOk();
        $this->post(self::BASE.'/interface-matrix/bulk', ['port_ids' => [$allowed->port_id], 'operation' => 'assign', 'policy_id' => $policy->id])->assertRedirect();
        $this->assertDatabaseHas('iapm_assignments', ['assignment_type' => 'port', 'assignment_reference' => (string) $allowed->port_id]);
    }

    public function test_device_and_static_group_grants_are_respected_and_dynamic_groups_require_the_core_opt_in(): void
    {
        $viewer = $this->userWith();
        $direct = $this->device(['hostname' => 'grant-direct']);
        $static = $this->device(['hostname' => 'grant-static']);
        $dynamic = $this->device(['hostname' => 'grant-dynamic']);
        foreach ([$direct, $static, $dynamic] as $device) {
            $this->downPort($device);
        }
        DB::table('devices_perms')->insert(['user_id' => $viewer->user_id, 'device_id' => $direct->device_id]);
        foreach ([['static', $static], ['dynamic', $dynamic]] as [$type, $device]) {
            $group = DeviceGroup::factory()->create(['type' => $type]);
            $device->groups()->attach($group->id);
            DB::table('devices_group_perms')->insert(['user_id' => $viewer->user_id, 'device_group_id' => $group->id]);
        }
        LibrenmsConfig::set('permission.device_group.allow_dynamic', false);
        $this->actingAs($viewer)->getJson(self::BASE.'/lookup/devices?q=grant-')->assertOk()->assertJsonCount(2);
        LibrenmsConfig::set('permission.device_group.allow_dynamic', true);
        $this->getJson(self::BASE.'/lookup/devices?q=grant-')->assertOk()->assertJsonCount(3);
    }

    public function test_plugin_abilities_alone_do_not_authorize_shared_configuration_writes(): void
    {
        $viewer = $this->userWith(['manage iapm policies', 'manage iapm assignments', 'manage iapm settings']);
        $this->actingAs($viewer)->get(self::BASE.'/policies')->assertOk();
        $this->get(self::BASE.'/settings')->assertOk();
        $this->post(self::BASE.'/policies', [])->assertForbidden();
        $this->put(self::BASE.'/settings', [])->assertForbidden();
        $this->post(self::BASE.'/interface-matrix/rebuild-cache')->assertForbidden();
    }

    public function test_import_requires_assignment_permission_in_addition_to_policy_permission(): void
    {
        $source = $this->defaultPolicy();
        $document = json_decode($this->actingAs($this->admin())->get(self::BASE.'/export')->assertOk()->streamedContent(), true, 64, JSON_THROW_ON_ERROR);
        $document['policies'][0]['name'] = 'Imported security policy';
        $manager = $this->userWith(['manage iapm policies', 'device.viewAll', 'port.viewAll']);
        $this->actingAs($manager)->post(self::BASE.'/import', ['document' => json_encode($document), 'action' => 'apply'])->assertForbidden();
        self::assertSame(1, Assignment::count());
        Permission::findOrCreate('manage iapm assignments', 'web');
        $manager->givePermissionTo('manage iapm assignments');
        $this->post(self::BASE.'/import', ['document' => json_encode($document), 'action' => 'apply'])->assertOk()->assertSessionHasNoErrors();
        self::assertSame(2, Assignment::count());
        self::assertSame(1, $source->assignments()->count());
    }

    private function userWith(array $abilities = []): User
    {
        $abilities = array_merge(['view iapm', 'device.view', 'port.view'], $abilities);
        foreach ($abilities as $ability) {
            Permission::findOrCreate($ability, 'web');
        }
        $user = User::factory()->create(['enabled' => true]);
        $user->givePermissionTo($abilities);

        return $user;
    }
}
