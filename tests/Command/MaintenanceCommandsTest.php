<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Command;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\DeliveryLog;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class MaintenanceCommandsTest extends IntegrationTestCase
{
    public function test_install_check_passes_on_a_fully_configured_installation(): void
    {
        $policy = $this->defaultPolicy();
        $this->triggerAction($policy, $this->smsDestination());

        $this->artisan('iapm:install-check')
            ->expectsOutputToContain('[OK] migrations')
            ->expectsOutputToContain('[OK] ingestion_token')
            ->expectsOutputToContain('[OK] default_policy')
            ->expectsOutputToContain('[OK] scheduler_registration')
            ->assertExitCode(0);
    }

    public function test_install_check_fails_when_the_ingestion_token_is_missing(): void
    {
        DB::table('iapm_settings')->where('setting_key', 'ingestion_token')->delete();
        $this->defaultPolicy();
        $this->smsDestination();

        $this->artisan('iapm:install-check')
            ->expectsOutputToContain('[FAIL] ingestion_token')
            ->assertExitCode(1);
    }

    public function test_cleanup_previews_without_deleting(): void
    {
        $this->settings->put('retention_days', 30);
        $policy = $this->defaultPolicy();
        $old = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Recovered, 'recovered_at' => now()->subDays(60)]);

        $this->artisan('iapm:cleanup')
            ->expectsOutputToContain('Dry-run only')
            ->assertExitCode(0);

        self::assertNotNull($old->fresh());
    }

    public function test_cleanup_with_force_removes_old_recovered_incidents_but_never_open_ones(): void
    {
        $this->settings->put('retention_days', 30);
        $policy = $this->defaultPolicy();
        $old = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Recovered, 'recovered_at' => now()->subDays(60)]);
        $recent = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Recovered, 'recovered_at' => now()->subDay()]);
        $open = $this->incident($policy, $this->downPort($this->device()));

        $this->artisan('iapm:cleanup --force')->assertExitCode(0);

        self::assertNull($old->fresh());
        self::assertNotNull($recent->fresh());
        self::assertNotNull($open->fresh());
    }

    public function test_cleanup_cascades_to_the_events_of_deleted_incidents(): void
    {
        $this->settings->put('retention_days', 30);
        $policy = $this->defaultPolicy();
        $old = $this->incident($policy, $this->downPort($this->device()), ['state' => IncidentState::Recovered, 'recovered_at' => now()->subDays(60)]);
        $old->events()->create(['event_type' => 'recovered', 'event_message' => 'Recovered long ago.']);

        $this->artisan('iapm:cleanup --force')->assertExitCode(0);

        self::assertSame(0, DB::table('iapm_incident_events')->where('incident_id', $old->id)->count());
    }

    public function test_test_policy_explains_the_effective_policy(): void
    {
        $policy = $this->defaultPolicy(['name' => 'Fallback policy']);
        $port = $this->downPort($this->device());

        $this->artisan("iapm:test-policy --port={$port->port_id}")
            ->expectsOutputToContain('Effective policy: Fallback policy')
            ->assertExitCode(0);
    }

    public function test_test_policy_reports_an_interface_without_a_policy(): void
    {
        $port = $this->downPort($this->device());

        $this->artisan("iapm:test-policy --port={$port->port_id}")
            ->expectsOutputToContain('No effective policy.')
            ->assertExitCode(1);
    }

    public function test_test_policy_rejects_an_unknown_port(): void
    {
        $this->artisan('iapm:test-policy --port=999999')->assertExitCode(2);
    }

    public function test_test_destination_requires_confirmation_before_sending(): void
    {
        Http::fake();
        $destination = $this->smsDestination();

        $this->artisan("iapm:test-destination --destination={$destination->id} --receiver=noc")
            ->expectsConfirmation('Send a real test notification?', 'no')
            ->assertExitCode(0);

        Http::assertNothingSent();
        self::assertSame(0, DeliveryLog::count());
    }

    public function test_test_destination_sends_and_records_the_attempt_with_force(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $destination = $this->smsDestination();

        $this->artisan("iapm:test-destination --destination={$destination->id} --receiver=noc --force")
            ->expectsOutputToContain('Test succeeded.')
            ->assertExitCode(0);

        $delivery = DeliveryLog::sole();
        self::assertSame('test', $delivery->phase);
        self::assertSame('sent', $delivery->status);
        self::assertNull($delivery->incident_id);
        Http::assertSent(fn ($request) => str_contains($request['message'], 'IAPM test message'));
    }

    public function test_test_destination_rejects_a_missing_receiver(): void
    {
        Http::fake();
        $destination = $this->smsDestination();

        $this->artisan("iapm:test-destination --destination={$destination->id} --receiver= --force")->assertExitCode(2);

        Http::assertNothingSent();
    }

    public function test_cache_clear_and_rebuild_maintain_the_policy_cache(): void
    {
        $this->defaultPolicy();
        $device = $this->device();
        $port = $this->downPort($device);

        $this->artisan("iapm:cache-rebuild --device={$device->device_id}")->assertExitCode(0);
        self::assertSame(1, DB::table('iapm_interface_policy_cache')->where('port_id', $port->port_id)->count());

        $this->artisan('iapm:cache-clear')->assertExitCode(0);
        self::assertSame(0, DB::table('iapm_interface_policy_cache')->count());
    }
}
