<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ReadinessService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\IntegrationTestCase;

class ReadinessTest extends IntegrationTestCase
{
    private function keyed(): array
    {
        return collect(app(ReadinessService::class)->checks())->keyBy('key')->map(fn ($c) => $c['ok'])->all();
    }

    public function test_a_fresh_install_reports_the_setup_steps_as_incomplete(): void
    {
        DB::table('iapm_settings')->truncate();
        $this->settings->forget('ingestion_token');
        $readiness = app(ReadinessService::class);

        self::assertFalse($readiness->ready());
        $checks = $this->keyed();
        self::assertTrue($checks['migrations'], 'RefreshDatabase means migrations are present.');
        self::assertFalse($checks['ingestion_token']);
        self::assertFalse($checks['enabled_destination']);
        self::assertFalse($checks['default_policy']);
    }

    public function test_configuring_everything_makes_it_ready(): void
    {
        $this->settings->put('ingestion_token', 'a-token');
        $policy = $this->defaultPolicy();
        $this->triggerAction($policy, $this->smsDestination());

        $readiness = app(ReadinessService::class);
        $checks = $this->keyed();

        self::assertTrue($checks['ingestion_token']);
        self::assertTrue($checks['policy_exists']);
        self::assertTrue($checks['default_policy']);
        self::assertTrue($checks['enabled_destination']);
        self::assertTrue($checks['sms_receiver']);
        self::assertTrue($readiness->ready(), 'All setup checks pass.');
    }

    public function test_disabling_record_unpoliced_satisfies_the_coverage_check(): void
    {
        $this->policy();   // a policy exists, but no default assignment / default policy
        self::assertFalse($this->keyed()['default_policy'], 'No default coverage configured yet.');

        // Intentionally ignoring unmatched interfaces is a valid coverage decision.
        $this->settings->put('record_unpoliced', false);
        self::assertTrue($this->keyed()['default_policy']);
    }

    public function test_disabled_policies_do_not_satisfy_default_coverage(): void
    {
        $assigned = $this->defaultPolicy(['enabled' => false]);
        self::assertFalse($this->keyed()['default_policy'], 'A default assignment on a disabled policy cannot provide coverage.');

        $assigned->assignments()->delete();
        $this->settings->put('default_policy_id', $assigned->id);
        self::assertFalse($this->keyed()['default_policy'], 'A disabled configured default policy cannot provide coverage.');

        $assigned->update(['enabled' => true]);
        self::assertTrue($this->keyed()['default_policy']);
    }

    public function test_the_alert_source_check_is_informational_not_a_setup_gate(): void
    {
        $this->settings->put('ingestion_token', 'a-token');
        $policy = $this->defaultPolicy();
        $this->triggerAction($policy, $this->smsDestination());

        $readiness = app(ReadinessService::class);
        $alertSource = collect($readiness->checks())->firstWhere('key', 'alert_source');

        self::assertSame('info', $alertSource['group']);
        self::assertFalse($alertSource['ok'], 'No incidents yet.');
        // ready() only considers setup-group checks, so an idle alert source does not block readiness.
        self::assertTrue($readiness->ready());
    }

    public function test_install_check_command_succeeds_when_configured(): void
    {
        $this->settings->put('ingestion_token', 'a-token');
        $policy = $this->defaultPolicy();
        $this->triggerAction($policy, $this->smsDestination());

        $this->artisan('iapm:install-check')
            ->expectsOutputToContain('[OK] ingestion_token')
            ->expectsOutputToContain('[OK] enabled_destination')
            ->assertExitCode(0);
    }

    public function test_install_check_command_fails_when_a_setup_step_is_missing(): void
    {
        DB::table('iapm_settings')->truncate();
        $this->settings->forget('ingestion_token');
        $this->smsDestination();

        $this->artisan('iapm:install-check')
            ->expectsOutputToContain('[FAIL] ingestion_token')
            ->assertExitCode(1);
    }

    public function test_the_overview_page_renders_the_setup_checklist_with_fix_links(): void
    {
        // setUp() sets the ingestion token but no destination/policy, so the
        // checklist is incomplete and must surface a fix action for the gaps.
        $this->actingAs($this->admin())
            ->get('/plugin/interface-alert-policy-manager')
            ->assertOk()
            ->assertSee('Setup checklist')
            ->assertSee('Create destination');
    }

    public function test_action_to_disabled_destination_is_not_ready_even_with_unrelated_enabled_destination(): void
    {
        $policy = $this->defaultPolicy();
        $disabled = $this->smsDestination();
        $disabled->update(['enabled' => false]);
        $this->triggerAction($policy, $disabled);
        $this->smsDestination();
        self::assertFalse($this->keyed()['policy_action']);
    }

    public function test_assignment_receiver_satisfies_sms_readiness(): void
    {
        $policy = $this->defaultPolicy();
        $policy->assignments()->first()->update(['metadata_json' => ['receivers' => ['assignment-noc']]]);
        $destination = $this->smsDestination(['default_receiver' => null]);
        $this->triggerAction($policy, $destination, ['receivers_json' => []]);
        $this->settings->put('sms_default_receiver', null);
        self::assertTrue($this->keyed()['sms_receiver']);
    }

    public function test_readiness_rejects_assignment_receiver_that_live_resolution_rejects(): void
    {
        $policy = $this->defaultPolicy(['default_receiver' => null]);
        $policy->assignments()->first()->update(['metadata_json' => ['receivers' => ['invalid/receiver']]]);
        $destination = $this->smsDestination(['default_receiver' => null]);
        $this->triggerAction($policy, $destination, ['receivers_json' => []]);
        $this->settings->put('sms_default_receiver', null);

        self::assertFalse($this->keyed()['sms_receiver']);
    }

    public function test_generic_webhook_does_not_require_an_sms_receiver(): void
    {
        $policy = $this->defaultPolicy();
        $destination = Destination::create(['name' => 'Webhook', 'type' => 'generic_webhook', 'enabled' => true, 'configuration_encrypted' => ['url' => 'https://example.test/hook']]);
        $this->triggerAction($policy, $destination, ['receivers_json' => []]);
        $this->settings->put('sms_default_receiver', null);
        self::assertTrue($this->keyed()['sms_receiver']);
    }

    public function test_unpoliced_ingestion_updates_alert_source_without_creating_incident(): void
    {
        $this->settings->put('record_unpoliced', false);
        $device = $this->device();
        $this->ingest($this->alertPayload($device, [$this->fault($this->downPort($device))]));
        self::assertTrue($this->keyed()['alert_source']);
    }
}
