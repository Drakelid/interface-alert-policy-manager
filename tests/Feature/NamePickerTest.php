<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\DeviceGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\AuditLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;
use Spatie\Permission\Models\Permission;

/**
 * P1-2: the administration screens repeatedly demanded internal primary keys in
 * free-text boxes for things the application already knows the name of.
 * P1-3: the logs printed those same bare ids back out.
 */
class NamePickerTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    /**
     * The regression that matters most: no filter or tool input should still be
     * a bare numeric text box asking for an internal id.
     */
    public function test_no_filter_asks_for_a_raw_internal_id(): void
    {
        $admin = $this->admin();

        foreach ([self::BASE.'/interface-matrix', self::BASE.'/delivery-log', self::BASE.'/audit-log'] as $path) {
            $body = (string) $this->actingAs($admin)->get($path)->assertOk()->getContent();
            foreach (['Device group ID', 'Device ID', 'Location ID', 'Incident ID', 'Destination ID', 'User ID'] as $legacyPlaceholder) {
                self::assertStringNotContainsString('placeholder="'.$legacyPlaceholder.'"', $body, "$path still asks for \"$legacyPlaceholder\".");
            }
        }
    }

    public function test_the_matrix_offers_device_group_and_location_selects(): void
    {
        $group = DeviceGroup::factory()->create(['name' => 'Core routers']);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/interface-matrix')->assertOk()->getContent();

        self::assertStringContainsString('Core routers', $body);
        self::assertStringContainsString('value="'.$group->id.'"', $body);
        // Devices are high-cardinality, so that one is a type-ahead, not a select.
        self::assertStringContainsString(route('iapm.lookup.devices'), $body);
    }

    public function test_the_device_lookup_returns_names_for_ids(): void
    {
        $device = $this->device(['hostname' => 'edge-router-01']);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/lookup/devices?q=edge-router')
            ->assertOk()
            ->assertJsonFragment(['id' => (int) $device->device_id]);
    }

    public function test_the_port_lookup_finds_an_interface_by_hostname_or_name(): void
    {
        $device = $this->device(['hostname' => 'agg-sw-07']);
        $port = $this->downPort($device, ['ifName' => 'ge-1/2/3', 'ifAlias' => 'CUST: Acme']);

        foreach (['agg-sw-07', 'ge-1/2/3', 'Acme'] as $term) {
            $this->actingAs($this->admin())
                ->getJson(self::BASE.'/lookup/ports?q='.urlencode($term))
                ->assertOk()
                ->assertJsonFragment(['id' => (int) $port->port_id]);
        }
    }

    public function test_the_port_lookup_excludes_ports_without_a_device(): void
    {
        $port = $this->downPort($this->device(), ['ifName' => 'orphaned-test-port']);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('ports')->where('port_id', $port->port_id)->update(['device_id' => 2147483647]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/lookup/ports?q=orphaned-test-port')
            ->assertOk()
            ->assertExactJson(['items' => [], 'has_more' => false, 'next_offset' => 0]);
    }

    public function test_the_port_lookup_pages_through_every_matching_interface(): void
    {
        $device = $this->device(['hostname' => 'dense-access-switch']);
        foreach (range(1, 55) as $index) {
            $this->downPort($device, ['ifName' => sprintf('Gi1/0/%02d', $index), 'ifAlias' => 'bulk-match']);
        }

        $first = $this->actingAs($this->admin())
            ->getJson(self::BASE.'/lookup/ports?q=bulk-match')
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonCount(50, 'items');

        $offset = $first->json('next_offset');
        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/lookup/ports?q=bulk-match&offset='.$offset)
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonCount(5, 'items');
    }

    /** An empty term must not turn the picker into an unbounded table scan. */
    public function test_lookups_return_nothing_for_an_empty_term(): void
    {
        $this->device(['hostname' => 'edge-router-01']);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/lookup/devices?q=')
            ->assertOk()
            ->assertExactJson([]);
    }

    /** A term containing LIKE wildcards must not widen the search. */
    public function test_lookup_terms_cannot_inject_like_wildcards(): void
    {
        $this->device(['hostname' => 'edge-router-01']);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/lookup/devices?q=%')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_the_user_lookup_requires_the_audit_log_ability(): void
    {
        Permission::findOrCreate('view iapm', 'web');
        $viewer = User::factory()->create(['enabled' => true]);
        $viewer->givePermissionTo('view iapm');

        $this->actingAs($viewer)->getJson(self::BASE.'/lookup/users?q=a')->assertForbidden();
        $this->actingAs($this->admin())->getJson(self::BASE.'/lookup/users?q=a')->assertOk();
    }

    /** P1-2: picking a header suggestion lands on that one interface. */
    public function test_the_matrix_can_be_filtered_to_a_single_port(): void
    {
        $device = $this->device();
        $wanted = $this->downPort($device, ['ifName' => 'wanted-if']);
        $this->downPort($device, ['ifName' => 'other-if']);

        $body = (string) $this->actingAs($this->admin())
            ->get(self::BASE.'/interface-matrix?port_id='.$wanted->port_id)
            ->assertOk()
            ->getContent();

        self::assertStringContainsString('wanted-if', $body);
        self::assertStringNotContainsString('other-if', $body);
    }

    /** P1-3: the Audit Log rendered the User column as a bare id. */
    public function test_the_audit_log_shows_the_username_not_the_id(): void
    {
        $admin = $this->admin();
        AuditLog::create(['user_id' => $admin->user_id, 'action' => 'updated', 'object_type' => 'policy', 'object_id' => 1, 'source_ip' => '127.0.0.1', 'created_at' => now()]);

        $body = (string) $this->actingAs($admin)->get(self::BASE.'/audit-log')->assertOk()->getContent();

        self::assertStringContainsString($admin->username, $body);
    }

    public function test_the_audit_log_falls_back_to_the_id_for_a_deleted_user(): void
    {
        AuditLog::create(['user_id' => 999999, 'action' => 'updated', 'object_type' => 'policy', 'object_id' => 1, 'source_ip' => '127.0.0.1', 'created_at' => now()]);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/audit-log')->assertOk()->getContent();

        self::assertStringContainsString('user 999999 (deleted)', $body);
    }

    /** P1-3: "incident 1" was plain text. */
    public function test_the_audit_log_links_an_incident_to_its_page(): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));
        AuditLog::create(['user_id' => null, 'action' => 'acknowledged', 'object_type' => 'incident', 'object_id' => $incident->id, 'source_ip' => '127.0.0.1', 'created_at' => now()]);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/audit-log')->assertOk()->getContent();

        self::assertStringContainsString(route('iapm.incidents.show', $incident), $body);
    }

    /** P1-3: the Delivery Log rendered "Dest: 1". */
    public function test_the_delivery_log_shows_the_destination_name(): void
    {
        $destination = $this->smsDestination();
        DeliveryLog::create(['destination_id' => $destination->id, 'phase' => 'trigger', 'status' => 'sent', 'created_at' => now()]);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/delivery-log')->assertOk()->getContent();

        self::assertStringContainsString($destination->name, $body);
        self::assertStringContainsString(route('iapm.destinations.edit', $destination), $body);
    }

    /**
     * The delivery log has no "deleted destination" case to render: destination_id
     * is non-nullable and restrictOnDelete, so a name always resolves. Pin that,
     * because the view relies on it instead of carrying a fallback branch.
     */
    public function test_a_destination_with_deliveries_cannot_be_deleted_out_from_under_the_log(): void
    {
        $destination = $this->smsDestination();
        DeliveryLog::create(['destination_id' => $destination->id, 'phase' => 'trigger', 'status' => 'sent', 'created_at' => now()]);

        $this->expectException(QueryException::class);
        $destination->delete();
    }

    /** The three tools that take a port_id now accept an interface search too. */
    public function test_the_port_tools_offer_an_interface_picker(): void
    {
        $admin = $this->admin();

        foreach ([self::BASE.'/policy-test', self::BASE.'/tools/simulate', self::BASE.'/template-preview'] as $path) {
            $body = (string) $this->actingAs($admin)->get($path)->assertOk()->getContent();
            self::assertStringContainsString(route('iapm.lookup.ports'), $body, "$path has no interface picker.");
            // The raw id stays available for anyone who already has it.
            self::assertStringContainsString('name="port_id"', $body, "$path dropped the raw port_id field.");
        }
    }

    /** P0-6: the instruction pointed at a lookup path that did not exist. */
    public function test_simulate_no_longer_sends_operators_hunting_for_a_port_id(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/tools/simulate')->assertOk()->getContent();

        self::assertStringNotContainsString('Use the Interface Matrix to find a port_id', $body);
    }

    /** The Audit Log's object-type select must offer what the recorder writes. */
    public function test_every_recorded_object_type_is_offered_as_a_filter(): void
    {
        $recorded = [];
        foreach (glob(dirname(__DIR__, 2).'/src/Http/Controllers/*.php') as $file) {
            preg_match_all("/->record\(\s*\\\$\w+,\s*'[a-z_]+',\s*'([a-z_]+)'/", (string) file_get_contents($file), $m);
            $recorded = array_merge($recorded, $m[1]);
        }

        self::assertNotEmpty($recorded);
        foreach (array_unique($recorded) as $type) {
            self::assertContains($type, AuditLog::OBJECT_TYPES, "AuditService records object_type '$type' but the filter does not offer it.");
        }
    }
}
