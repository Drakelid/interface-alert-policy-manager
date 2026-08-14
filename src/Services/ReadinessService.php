<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;

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
    private const TABLES = ['iapm_policies', 'iapm_assignments', 'iapm_destinations', 'iapm_policy_actions', 'iapm_incidents', 'iapm_incident_events', 'iapm_delivery_logs', 'iapm_ingestion_inbox', 'iapm_notification_outbox', 'iapm_notification_outbox_incidents', 'iapm_outages', 'iapm_simulations', 'iapm_settings', 'iapm_interface_policy_cache', 'iapm_audit_logs'];

    public function __construct(private readonly SettingStore $settings, private readonly ReceiverResolver $receivers) {}

    /** @return list<array<string,mixed>> */
    public function checks(): array
    {
        $migrated = collect(self::TABLES)->every(fn ($table) => Schema::hasTable($table));

        return [
            // `hint` is phrased as a condition because it is rendered in the UI,
            // where a web-only administrator cannot act on a shell command (P1-7).
            // `cli_hint` carries the command for iapm:install-check, which is
            // already being run from a shell.
            $this->check('migrations', 'Database migrated', $migrated, 'system', 'IAPM tables are missing — the database migration has not been applied on this host.', cliHint: 'Run: php artisan migrate --force'),
            $this->check('encryption_key', 'Application encryption key present', filled(config('app.key')), 'system', 'LibreNMS APP_KEY is required to encrypt destination secrets.'),
            $this->check('writable_storage', 'Log path writable', is_writable(storage_path('logs')), 'system', 'storage/logs must be writable by the web and cron users.'),
            $this->check('ingestion_token', 'Ingestion token generated', $migrated && filled($this->settings->get('ingestion_token')), 'setup', 'Generate the bearer token LibreNMS uses to post alerts.', 'iapm.settings.edit', 'Generate token'),
            $this->check('policy_exists', 'At least one enabled policy', $migrated && Policy::where('enabled', true)->exists(), 'setup', 'Create a policy describing trigger, repeat, and recovery behaviour.', 'iapm.policies.create', 'Create policy'),
            $this->check('policy_action', 'A policy has a usable notification action', $migrated && Policy::where('enabled', true)->whereHas('actions', fn ($q) => $q->where('enabled', true)->whereHas('destination', fn ($destination) => $destination->where('enabled', true)))->exists(), 'setup', 'Add an enabled action connected to an enabled destination.', 'iapm.policies.index', 'Open policies'),
            $this->check('default_policy', 'Coverage for unmatched interfaces decided', $migrated && $this->hasDefaultPolicy(), 'setup', 'Open a policy and add a default assignment so interfaces without a specific match are covered — or turn off "Record alerts for interfaces with no policy" (Settings) to intentionally ignore them.', 'iapm.policies.index', 'Open policies'),
            $this->check('enabled_destination', 'An enabled delivery destination', $migrated && Destination::where('enabled', true)->exists(), 'setup', 'Create the SMS gateway (or webhook) destination that will deliver notifications.', 'iapm.destinations.create', 'Create destination'),
            $this->check('sms_receiver', 'SMS actions have a resolvable receiver', $migrated && $this->hasReceiver(), 'setup', 'Set an action, assignment, policy, destination, or global SMS receiver.', 'iapm.settings.edit', 'Set receiver'),
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
        // Recording unpoliced interfaces off = they are intentionally ignored, so a
        // default assignment/policy is not required — the coverage decision is made.
        if (! (bool) $this->settings->get('record_unpoliced', true)) {
            return true;
        }

        $enabledDefaultAssignment = DB::table('iapm_assignments')
            ->join('iapm_policies', 'iapm_policies.id', '=', 'iapm_assignments.policy_id')
            ->where('iapm_assignments.assignment_type', 'default')
            ->where('iapm_assignments.enabled', true)
            ->where('iapm_policies.enabled', true)
            ->exists();
        $configuredDefault = $this->settings->get('default_policy_id');

        return $enabledDefaultAssignment
            || (filled($configuredDefault) && Policy::whereKey($configuredDefault)->where('enabled', true)->exists());
    }

    private function hasReceiver(): bool
    {
        $actions = PolicyAction::query()->where('enabled', true)->whereHas('policy', fn ($query) => $query->where('enabled', true))->whereHas('destination', fn ($query) => $query->where('enabled', true))->with(['policy.assignments' => fn ($query) => $query->where('enabled', true), 'destination'])->get();
        if ($actions->isEmpty()) {
            return false;
        }

        return $actions->every(function (PolicyAction $action): bool {
            if ($action->destination->type !== 'sms_gateway') {
                return true;
            }

            return $this->receivers->forReadiness($action) !== [];
        });
    }

    private function hasReceivedAlerts(): bool
    {
        if (! (bool) $this->settings->get('record_unpoliced', true)) {
            return filled($this->settings->get('last_ingestion_at'));
        }

        return Incident::query()->exists() || filled($this->settings->get('last_ingestion_at'));
    }

    private function check(string $key, string $label, bool $ok, string $group, string $hint, ?string $route = null, ?string $action = null, ?string $cliHint = null): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'group' => $group, 'hint' => $hint, 'route' => $route, 'action' => $action, 'cli_hint' => $cliHint];
    }
}
