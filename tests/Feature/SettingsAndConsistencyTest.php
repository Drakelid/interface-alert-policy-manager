<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use App\Models\User;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\AuditLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;
use Spatie\Permission\Models\Permission;

/**
 * P2-6 through P2-11: layout collisions, page titles, settings navigation, the
 * dry-run guard, log exports and bulk-action affordances.
 */
class SettingsAndConsistencyTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    /** P2-7: title, heading and active filter must agree. */
    public function test_the_incidents_page_titles_match_the_active_filter(): void
    {
        $admin = $this->admin();

        $default = (string) $this->actingAs($admin)->get(self::BASE.'/incidents')->assertOk()->getContent();
        self::assertStringContainsString('<title>IAPM Open incidents', $default);
        self::assertStringContainsString('>Open incidents</h1>', $default);
        self::assertStringNotContainsString('Active Incidents', $default);

        $active = (string) $this->actingAs($admin)->get(self::BASE.'/incidents?state=active')->assertOk()->getContent();
        self::assertStringContainsString('>Active incidents</h1>', $active);

        $all = (string) $this->actingAs($admin)->get(self::BASE.'/incidents?state=all')->assertOk()->getContent();
        self::assertStringContainsString('>All incidents</h1>', $all);
    }

    public function test_the_heading_reflects_severity_and_escalation_filters(): void
    {
        $body = (string) $this->actingAs($this->admin())
            ->get(self::BASE.'/incidents?state=active&severity=critical&escalation=pending')
            ->assertOk()
            ->getContent();

        self::assertStringContainsString('Active incidents — critical awaiting escalation', $body);
    }

    /** The default view really does include acknowledged incidents, as it says. */
    public function test_the_default_view_shows_every_open_state(): void
    {
        $policy = $this->policy();
        $device = $this->device();
        foreach ([IncidentState::Active, IncidentState::Pending, IncidentState::Acknowledged, IncidentState::Suppressed] as $state) {
            $this->incident($policy, $this->downPort($device), ['state' => $state]);
        }
        $this->incident($policy, $this->downPort($device), ['state' => IncidentState::Recovered, 'recovered_at' => now()]);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/incidents')->assertOk()->getContent();

        self::assertStringContainsString('data-iapm-total="4"', $body, 'The open view should list the four non-recovered incidents.');
    }

    /** P2-8: the menu item dropped the operator at the top of a long page. */
    public function test_the_token_menu_item_deep_links_to_its_section(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE)->assertOk()->getContent();

        self::assertStringContainsString(route('iapm.settings.edit').'#ingestion-token', $body);
    }

    public function test_settings_sections_have_anchors_and_a_jump_list(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/settings')->assertOk()->getContent();

        foreach (['ingestion-token', 'delivery-mode', 'policy-defaults', 'delivery-retention', 'delivery-dispatch', 'storm-control', 'root-cause'] as $anchor) {
            self::assertStringContainsString('id="'.$anchor.'"', $body, "Settings has no #$anchor section.");
            self::assertStringContainsString('href="#'.$anchor.'"', $body, "The jump list does not offer #$anchor.");
        }
        self::assertStringContainsString('iapm-sticky-save', $body);
    }

    /** P2-9: going live must be guarded and then confirmed. */
    public function test_the_dry_run_toggle_warns_before_and_confirms_after(): void
    {
        $this->settings->put('dry_run', true);

        $form = (string) $this->actingAs($this->admin())->get(self::BASE.'/settings')->assertOk()->getContent();
        self::assertStringContainsString('id="iapm-going-live"', $form);
        self::assertStringContainsString('This will start sending real notifications', $form);
        self::assertStringContainsString('GO LIVE', $form);

        $this->actingAs($this->admin())->put(self::BASE.'/settings', $this->settingsPayload(['dry_run' => '0']))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Dry-run is OFF'));

        self::assertFalse((bool) $this->settings->get('dry_run'));
    }

    public function test_returning_to_dry_run_is_also_confirmed(): void
    {
        $this->settings->put('dry_run', false);

        $this->actingAs($this->admin())->put(self::BASE.'/settings', $this->settingsPayload(['dry_run' => '1']))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Dry-run is ON'));
    }

    public function test_an_unrelated_settings_change_does_not_claim_a_mode_change(): void
    {
        $this->settings->put('dry_run', true);

        $this->actingAs($this->admin())->put(self::BASE.'/settings', $this->settingsPayload(['dry_run' => '1', 'retention_days' => 30]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Settings updated.');
    }

    /** P2-10: both logs export CSV and accept a date range. */
    public function test_the_delivery_log_exports_csv_within_a_date_range(): void
    {
        $destination = $this->smsDestination();
        DeliveryLog::create(['destination_id' => $destination->id, 'phase' => 'trigger', 'status' => 'sent', 'created_at' => now()->subDay()]);
        DeliveryLog::create(['destination_id' => $destination->id, 'phase' => 'recovery', 'status' => 'failed', 'created_at' => now()->subDays(30)]);

        $csv = $this->actingAs($this->admin())
            ->get(self::BASE.'/delivery-log/export?from='.now()->subDays(3)->format('Y-m-d'))
            ->assertOk()
            ->streamedContent();

        self::assertStringContainsString('time,incident_id,destination,phase,status', $csv);
        self::assertStringContainsString('trigger', $csv);
        self::assertStringNotContainsString('recovery', $csv, 'The 30-day-old row is outside the requested range.');
        // The name, not the id (P1-3).
        self::assertStringContainsString($destination->name, $csv);
    }

    public function test_the_audit_log_exports_csv_with_usernames(): void
    {
        $admin = $this->admin();
        AuditLog::create(['user_id' => $admin->user_id, 'action' => 'updated', 'object_type' => 'policy', 'object_id' => 1, 'source_ip' => '127.0.0.1', 'created_at' => now()]);

        $csv = $this->actingAs($admin)->get(self::BASE.'/audit-log/export')->assertOk()->streamedContent();

        self::assertStringContainsString('time,user,action,object_type', $csv);
        self::assertStringContainsString($admin->username, $csv);
    }

    public function test_log_exports_require_the_audit_ability(): void
    {
        $user = User::factory()->create(['enabled' => true]);
        Permission::findOrCreate('view iapm', 'web');
        $user->givePermissionTo('view iapm');

        $this->actingAs($user)->get(self::BASE.'/audit-log/export')->assertForbidden();
        $this->actingAs($user)->get(self::BASE.'/delivery-log/export')->assertForbidden();
    }

    public function test_both_logs_offer_a_date_range_and_an_export_button(): void
    {
        $admin = $this->admin();

        foreach (['delivery-log', 'audit-log'] as $log) {
            $body = (string) $this->actingAs($admin)->get(self::BASE.'/'.$log)->assertOk()->getContent();
            self::assertStringContainsString('name="from"', $body, "$log has no date-range start.");
            self::assertStringContainsString('name="to"', $body, "$log has no date-range end.");
            self::assertStringContainsString($log.'/export', $body, "$log has no CSV export.");
        }
    }

    /** P2-11: red bulk buttons were enabled with nothing selected. */
    public function test_bulk_delete_buttons_start_disabled(): void
    {
        $this->policy();
        $this->smsDestination();
        $this->policy()->assignments()->create(['assignment_type' => 'default', 'match_mode' => 'any', 'priority' => 0, 'enabled' => true]);
        $admin = $this->admin();

        foreach (['policies', 'destinations', 'assignments'] as $list) {
            $body = (string) $this->actingAs($admin)->get(self::BASE.'/'.$list)->assertOk()->getContent();
            self::assertStringContainsString('data-iapm-bulk-button="'.$list.'" disabled', $body, "The $list bulk button is not disabled by default.");
            self::assertStringContainsString('data-iapm-bulk-scope="'.$list.'"', $body);
            self::assertStringContainsString('data-iapm-bulk-count', $body);
        }
    }

    /** P2-10: one time presentation, relative text plus an exact title. */
    public function test_times_carry_an_absolute_timestamp(): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()));

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/incidents/{$incident->id}")->assertOk()->getContent();

        self::assertMatchesRegularExpression('/<time datetime="[^"]+" title="\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \w+">/', $body);
    }

    /** P2-6: the two buttons were rendering butted together. */
    public function test_the_incident_actions_are_spaced(): void
    {
        $incident = $this->incident($this->policy(), $this->downPort($this->device()), ['state' => IncidentState::Acknowledged]);

        $body = (string) $this->actingAs($this->admin())->get(self::BASE."/incidents/{$incident->id}")->assertOk()->getContent();

        // The fix is the flex row: inline forms have no gap of their own, so the
        // container is what separates them.
        self::assertStringContainsString('panel-body iapm-action-row', $body);
        preg_match('#<div class="panel-body iapm-action-row">(.*?)</div>#s', $body, $panel);
        self::assertNotEmpty($panel, 'The actions panel was not found.');
        self::assertStringNotContainsString('style="display:inline;"', $panel[1], 'Actions still rely on inline display with no spacing.');
    }

    /** @return array<string, mixed> */
    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'dry_run' => '1', 'retention_days' => 365, 'notification_timeout' => 15,
            'notification_retry_count' => 2, 'deleted_port_behavior' => 'recover',
            'aggregate_threshold' => 0, 'aggregate_window_seconds' => 120,
            'dispatch_mode' => 'sync', 'record_unpoliced' => '1',
        ], $overrides);
    }
}
