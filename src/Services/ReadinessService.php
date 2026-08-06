<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Illuminate\Support\Facades\Schema;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;

/**
 * Shared installation/configuration readiness checks, used both by the
 * iapm:install-check command and the in-UI setup checklist so the CLI and the
 * browser never disagree about what is left to configure.
 *
 * Each check is an array:
 *   key      machine name (matches the CLI output)
 *   label    human label
 *   ok       bool
 *   group    'setup' (admin action needed) | 'system' (environment)
 *   hint     one line of guidance shown when not ok
 *   route    named route to fix it (nullable)
 *   action   button label for the fix route (nullable)
 */
class ReadinessService
{
    private const TABLES = ['iapm_policies', 'iapm_assignments', 'iapm_destinations', 'iapm_policy_actions', 'iapm_incidents', 'iapm_incident_events', 'iapm_delivery_logs', 'iapm_settings', 'iapm_interface_policy_cache', 'iapm_audit_logs'];

    public function __construct(private readonly SettingStore $settings) {}

    /** @return list<array<string,mixed>> */
    public function checks(): array
    {
        $migrated = collect(self::TABLES)->every(fn ($table) => Schema::hasTable($table));

        return [
            $this->check('migrations', 'Database migrated', $migrated, 'system', 'Run: php artisan migrate --force'),
            $this->check('encryption_key', 'Application encryption key present', filled(config('app.key')), 'system', 'LibreNMS APP_KEY is required to encrypt destination secrets.'),
            $this->check('writable_storage', 'Log path writable', is_writable(storage_path('logs')), 'system', 'storage/logs must be writable by the web and cron users.'),
            $this->check('ingestion_token', 'Ingestion token generated', $migrated && filled($this->settings->get('ingestion_token')), 'setup', 'Generate the bearer token LibreNMS uses to post alerts.', 'iapm.settings.edit', 'Generate token'),
            $this->check('policy_exists', 'At least one enabled policy', $migrated && Policy::where('enabled', true)->exists(), 'setup', 'Create a policy describing trigger, repeat, and recovery behaviour.', 'iapm.policies.create', 'Create policy'),
            $this->check('policy_action', 'A policy has an enabled notification action', $migrated && Policy::where('enabled', true)->whereHas('actions', fn ($q) => $q->where('enabled', true))->exists(), 'setup', 'Add an enabled action to a policy — without one, matched interfaces trigger incidents but never notify.', 'iapm.policies.index', 'Open policies'),
            $this->check('default_policy', 'A default assignment or default policy', $migrated && $this->hasDefaultPolicy(), 'setup', 'Add a default assignment so interfaces without a specific match are still covered.', 'iapm.assignments.create', 'Create assignment'),
            $this->check('enabled_destination', 'An enabled delivery destination', $migrated && Destination::where('enabled', true)->exists(), 'setup', 'Create the SMS gateway (or webhook) destination that will deliver notifications.', 'iapm.destinations.create', 'Create destination'),
            $this->check('sms_receiver', 'A resolvable default receiver', $migrated && $this->hasReceiver(), 'setup', 'Set a global SMS receiver or a per-destination default receiver.', 'iapm.settings.edit', 'Set receiver'),
            $this->check('alert_source', 'LibreNMS is posting alerts', $migrated && $this->hasReceivedAlerts(), 'info', 'Configure the LibreNMS alert rule, template and API transport to post here.', 'iapm.setup-helper', 'Open setup helper'),
        ];
    }

    public function ready(): bool
    {
        return collect($this->checks())->where('group', 'setup')->every(fn ($check) => $check['ok']);
    }

    public function dryRun(): bool
    {
        return (bool) $this->settings->get('dry_run', true);
    }

    private function hasDefaultPolicy(): bool
    {
        return \Illuminate\Support\Facades\DB::table('iapm_assignments')->where('assignment_type', 'default')->where('enabled', true)->exists()
            || filled($this->settings->get('default_policy_id'));
    }

    private function hasReceiver(): bool
    {
        if (filled($this->settings->get('sms_default_receiver', config('iapm.sms.default_receiver')))) {
            return true;
        }

        return Destination::where('type', 'sms_gateway')->get()->contains(fn ($d) => filled($d->configuration_encrypted['default_receiver'] ?? null));
    }

    private function hasReceivedAlerts(): bool
    {
        return \LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident::query()->exists();
    }

    private function check(string $key, string $label, bool $ok, string $group, string $hint, ?string $route = null, ?string $action = null): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'group' => $group, 'hint' => $hint, 'route' => $route, 'action' => $action];
    }
}
