<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

/**
 * P2-5: the field labelled "Down observations" posted as `failed_poll_count`,
 * and its own help text said it explicitly does not count polls —
 * reconciliation increments it once a minute while the interface stays down.
 */
class DownObservationsRenameTest extends IntegrationTestCase
{
    private const BASE = '/plugin/interface-alert-policy-manager';

    public function test_the_column_is_renamed(): void
    {
        self::assertTrue(Schema::hasColumn('iapm_policies', 'down_observations'));
        self::assertFalse(Schema::hasColumn('iapm_policies', 'failed_poll_count'));
    }

    public function test_the_form_posts_the_new_field_name(): void
    {
        $body = (string) $this->actingAs($this->admin())->get(self::BASE.'/policies/create')->assertOk()->getContent();

        self::assertStringContainsString('name="down_observations"', $body);
        self::assertStringNotContainsString('name="failed_poll_count"', $body);
    }

    public function test_a_policy_saves_through_the_new_field(): void
    {
        $this->actingAs($this->admin())->post(self::BASE.'/policies', [
            'name' => 'Renamed field', 'severity' => 'critical', 'priority' => 0,
            'trigger_after_seconds' => 0, 'down_observations' => 4, 'recovery_after_seconds' => 0,
            'enabled' => '1', 'notifications_enabled' => '1',
        ])->assertRedirect();

        self::assertSame(4, Policy::where('name', 'Renamed field')->firstOrFail()->down_observations);
    }

    /** Export must emit only the new key. */
    public function test_export_uses_the_new_key(): void
    {
        $this->policy(['name' => 'Exported', 'down_observations' => 3]);

        $json = $this->actingAs($this->admin())->get(self::BASE.'/export')->assertOk()->streamedContent();

        self::assertStringContainsString('"down_observations": 3', $json);
        self::assertStringNotContainsString('failed_poll_count', $json);
    }

    /** A document exported before the rename must still import. */
    public function test_import_accepts_the_old_key(): void
    {
        $document = $this->documentWith(['failed_poll_count' => 7]);

        $this->actingAs($this->admin())
            ->post(self::BASE.'/import', ['document' => $document, 'action' => 'apply'])
            ->assertOk();

        self::assertSame(7, Policy::where('name', 'Legacy key policy')->firstOrFail()->down_observations);
    }

    public function test_import_accepts_the_new_key(): void
    {
        $this->actingAs($this->admin())
            ->post(self::BASE.'/import', ['document' => $this->documentWith(['down_observations' => 5]), 'action' => 'apply'])
            ->assertOk();

        self::assertSame(5, Policy::where('name', 'Legacy key policy')->firstOrFail()->down_observations);
    }

    /** If a document carries both, the new key is authoritative. */
    public function test_the_new_key_wins_when_both_are_present(): void
    {
        $this->actingAs($this->admin())
            ->post(self::BASE.'/import', ['document' => $this->documentWith(['failed_poll_count' => 7, 'down_observations' => 2]), 'action' => 'apply'])
            ->assertOk();

        self::assertSame(2, Policy::where('name', 'Legacy key policy')->firstOrFail()->down_observations);
    }

    /** The rename must not leave the runtime behaviour changed. */
    public function test_the_field_still_gates_when_an_incident_becomes_active(): void
    {
        $policy = $this->defaultPolicy(['trigger_after_seconds' => 0, 'down_observations' => 3]);
        $device = $this->device();
        $port = $this->downPort($device);

        $this->ingest($this->alertPayload($device, [$this->fault($port)]))->assertOk();

        // One observation of three: still pending, not active.
        self::assertSame('pending', $policy->incidents()->firstOrFail()->state->value);
    }

    private function documentWith(array $overrides): string
    {
        $destination = $this->smsDestination();
        $destination->update(['name' => 'Gateway']);

        return json_encode([
            'version' => 1,
            'schedules' => [],
            'policies' => [array_merge([
                'name' => 'Legacy key policy', 'description' => null, 'enabled' => true, 'priority' => 0,
                'severity' => 'critical', 'default_receiver' => null, 'notifications_enabled' => true,
                'trigger_after_seconds' => 0, 'recovery_after_seconds' => 0,
                'repeat_seconds' => null, 'maximum_repeats' => null, 'notify_recovery' => true,
                'suppress_device_down' => true, 'suppress_admin_down' => true, 'suppress_ignored_port' => true,
                'suppress_disabled_port' => true, 'suppress_deleted_port' => true, 'suppress_maintenance' => true,
                'suppress_parent_down' => true, 'suppress_uplink_down' => false,
                'flap_threshold' => null, 'flap_window_seconds' => null, 'flap_settle_seconds' => null,
                'schedule' => null, 'actions' => [], 'assignments' => [],
            ], $overrides)],
        ]);
    }
}
