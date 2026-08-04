<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests;

use App\Models\Device;
use App\Models\Port;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\IapmServiceProvider;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Tests\TestCase;
use Spatie\Permission\Models\Role;

/**
 * Base class for tests that need the LibreNMS application and database.
 */
abstract class IntegrationTestCase extends TestCase
{
    use RefreshDatabase;

    protected SettingStore $settings;

    protected function setUp(): void
    {
        parent::setUp();

        // A stray request would mean a test could reach the production gateway.
        Http::preventStrayRequests();

        // The plugins table did not exist when the provider booted, so materialize
        // the plugin row the way `lnms plugin:add` does.
        app(PluginManagerInterface::class)->pluginEnabled(IapmServiceProvider::PLUGIN_NAME);

        $this->settings = app(SettingStore::class);
        $this->settings->put('dry_run', false);
        $this->settings->put('ingestion_token', 'test-ingestion-token');
    }

    protected function device(array $attributes = []): Device
    {
        return Device::factory()->create(array_merge(['status' => 1, 'status_reason' => ''], $attributes));
    }

    protected function downPort(Device $device, array $attributes = []): Port
    {
        return Port::factory()->create(array_merge([
            'device_id' => $device->device_id,
            'ifName' => 'xe-0/0/'.fake()->unique()->numberBetween(1, 9999),
            'ifAlias' => 'CUST: Example customer',
            'ifType' => 'ethernetCsmacd',
            'ifAdminStatus' => 'up',
            'ifOperStatus' => 'down',
            'ignore' => 0,
            'disabled' => 0,
            'deleted' => 0,
        ], $attributes));
    }

    protected function policy(array $attributes = []): Policy
    {
        return Policy::create(array_merge([
            'name' => 'Policy '.fake()->unique()->numberBetween(1, 99999),
            'enabled' => true,
            'priority' => 0,
            'severity' => 'critical',
            'notifications_enabled' => true,
            'trigger_after_seconds' => 0,
            'failed_poll_count' => 1,
            'recovery_after_seconds' => 0,
            'notify_recovery' => true,
            'suppress_device_down' => true,
            'suppress_admin_down' => true,
            'suppress_ignored_port' => true,
            'suppress_disabled_port' => true,
            'suppress_deleted_port' => true,
            'suppress_maintenance' => true,
            'suppress_parent_down' => true,
        ], $attributes));
    }

    protected function defaultPolicy(array $attributes = []): Policy
    {
        $policy = $this->policy($attributes);
        $policy->assignments()->create(['assignment_type' => 'default', 'match_mode' => 'any', 'priority' => 0, 'enabled' => true]);

        return $policy;
    }

    protected function smsDestination(array $configuration = []): Destination
    {
        return Destination::create([
            'name' => 'Gateway '.fake()->unique()->numberBetween(1, 99999),
            'type' => 'sms_gateway',
            'enabled' => true,
            // An IP literal keeps UrlGuard from performing DNS lookups in tests,
            // and mirrors the trusted internal gateway of a real deployment.
            'configuration_encrypted' => array_merge([
                'url' => 'http://127.0.0.1:5000/api/v10/messages/send',
                'username' => 'gateway-user',
                'password' => 'gateway-password',
                'default_receiver' => 'noc',
                'mode' => 'json',
                'verify_tls' => true,
                'retry_count' => 0,
                'retry_delay_ms' => 0,
                'allow_private_networks' => true,
            ], $configuration),
        ]);
    }

    protected function incident(Policy $policy, Port $port, array $attributes = []): Incident
    {
        $context = (array) app(InterfaceContextService::class)->forPort($port);
        $context['assignment_source'] = 'default';
        $context['observation_count'] = 1;

        return Incident::create(array_merge([
            'incident_key' => Incident::key((int) $port->device_id, (int) $port->port_id),
            'source_alert_id' => 572,
            'source_alert_uid' => '18432',
            'source_rule_id' => 14,
            'device_id' => $port->device_id,
            'port_id' => $port->port_id,
            'policy_id' => $policy->id,
            'state' => IncidentState::Active,
            'severity' => 'critical',
            'first_seen_at' => now()->subMinutes(10),
            'triggered_at' => now()->subMinutes(10),
            'last_seen_at' => now(),
            'notification_count' => 0,
            'context_json' => $context,
        ], $attributes));
    }

    protected function triggerAction(Policy $policy, Destination $destination, array $attributes = []): PolicyAction
    {
        return $policy->actions()->create(array_merge([
            'destination_id' => $destination->id,
            'phase' => 'trigger',
            'delay_seconds' => 0,
            'enabled' => true,
            'sort_order' => 0,
        ], $attributes));
    }

    protected function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /** @return array<string, mixed> */
    protected function alertPayload(Device $device, array $faults, int $state = 1, array $overrides = []): array
    {
        return array_merge([
            'alert_uid' => '18432',
            'alert_id' => 572,
            'rule_id' => 14,
            'device_id' => $device->device_id,
            'hostname' => $device->hostname,
            'state' => $state,
            'severity' => 'critical',
            'timestamp' => now()->toIso8601String(),
            'title' => 'Interface alert',
            'faults' => $faults,
        ], $overrides);
    }

    protected function ingest(array $payload, ?string $token = 'test-ingestion-token'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/plugin/interface-alert-policy-manager/api/v1/alerts', $payload, array_filter([
            'Authorization' => $token ? "Bearer $token" : null,
        ]));
    }

    /** @return array<int, array<string, mixed>> */
    protected function fault(Port $port): array
    {
        return ['port_id' => $port->port_id, 'ifName' => $port->ifName, 'ifDescr' => $port->ifDescr, 'ifAlias' => $port->ifAlias, 'ifAdminStatus' => 'up', 'ifOperStatus' => 'down'];
    }
}
