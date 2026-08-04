<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Illuminate\Console\Command;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\Concerns\SkipsWhenPluginDisabled;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\NotificationDispatcher;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ReceiverResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SafeTemplateRenderer;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\TemplateContextBuilder;

class ProcessActionsCommand extends Command
{
    use SkipsWhenPluginDisabled;

    protected $signature = 'iapm:process-actions {--incident=} {--action=} {--force : Send even when a successful delivery exists}';
    protected $description = 'Activate eligible pending incidents and process due IAPM actions';

    public function handle(NotificationDispatcher $dispatcher, ReceiverResolver $receivers, SafeTemplateRenderer $templates, SettingStore $settings, TemplateContextBuilder $placeholders): int
    {
        if ($this->pluginDisabled()) {
            return self::SUCCESS;
        }

        $processed = 0;
        Incident::query()->whereIn('state', [IncidentState::Pending, IncidentState::Active, IncidentState::Acknowledged, IncidentState::Recovered])->when($this->option('incident'), fn ($q, $id) => $q->whereKey($id))->with(['policy.actions.destination'])->chunkById((int) config('iapm.processing.batch_size', 500), function ($items) use (&$processed, $dispatcher, $receivers, $templates, $settings, $placeholders): void {
            foreach ($items as $incident) {
                if ($incident->state === IncidentState::Pending && $incident->policy && $incident->first_seen_at->addSeconds($incident->policy->trigger_after_seconds)->isPast() && (int) ($incident->context_json['observation_count'] ?? 1) >= $incident->policy->failed_poll_count) {
                    $incident->update(['state' => IncidentState::Active, 'triggered_at' => now()]);
                    $incident->events()->create(['event_type' => 'activated', 'event_message' => 'Trigger requirements satisfied.']);
                }
                if (! $incident->policy || ! $incident->policy->notifications_enabled) continue;
                $phases = match ($incident->state) { IncidentState::Recovered => ['recovery'], IncidentState::Acknowledged => ['acknowledged'], IncidentState::Active => ['trigger', 'escalation', 'reminder'], default => [] };
                if ($incident->muted_until?->isFuture()) continue;
                foreach ($phases as $phase) {
                if ($phase === 'recovery' && ! $incident->policy->notify_recovery) continue;
                foreach ($incident->policy->actions->filter(fn ($action) => $action->enabled && $action->phase->value === $phase && (! $this->option('action') || (int) $this->option('action') === (int) $action->id)) as $action) {
                    if (! $this->option('force') && $incident->deliveries()->where('policy_action_id', $action->id)->where('phase', $phase)->where('status', 'failed_configuration')->where('created_at', '>=', now()->subMinutes(5))->exists()) continue;
                    $successful = $incident->deliveries()->where('policy_action_id', $action->id)->where('phase', $phase)->whereIn('status', ['sent', 'dry_run']);
                    $sendCount = (clone $successful)->count(); $lastSend = (clone $successful)->latest('created_at')->first();
                    $repeatSeconds = $action->repeat_seconds ?? (in_array($phase, ['trigger','reminder'], true) ? $incident->policy->repeat_seconds : null);
                    $maximumSends = $action->maximum_sends ?? ($incident->policy->maximum_repeats === null ? null : 1 + $incident->policy->maximum_repeats);
                    if (! $this->option('force') && $sendCount > 0 && ($repeatSeconds === null || ($maximumSends !== null && $sendCount >= $maximumSends))) continue;
                    if (! $this->option('force') && $lastSend && $repeatSeconds !== null && $lastSend->created_at->addSeconds($repeatSeconds)->isFuture()) continue;
                    $phaseStart = match ($phase) { 'recovery' => $incident->recovered_at, 'acknowledged' => $incident->acknowledged_at, default => $incident->triggered_at ?? $incident->first_seen_at };
                    if (! $phaseStart || (! $this->option('force') && $phaseStart->addSeconds($action->delay_seconds)->isFuture())) continue;
                    $config = (array) $action->destination->configuration_encrypted;
                    $resolved = $receivers->resolve((array) $action->receivers_json, (array) ($incident->context_json['assignment_receivers'] ?? []), (array) ($incident->context_json['device_group_receivers'] ?? []), [(string) ($incident->policy->default_receiver ?? '')], (array) ($config['receivers'] ?? []), [(string) ($config['default_receiver'] ?? '')], [(string) $settings->get('sms_default_receiver', config('iapm.sms.default_receiver'))]);
                    if ($resolved === []) { $dispatcher->configurationFailure($incident, $action->destination, $action, $phase, 'No notification receiver could be resolved.'); continue; }
                    try { $message = $templates->render($action->message_template ?: self::defaultTemplate($phase), $placeholders->forIncident($incident), (int) config('iapm.sms.message_length', 480)); } catch (\Throwable $e) { $dispatcher->configurationFailure($incident, $action->destination, $action, $phase, 'Template error: '.$e->getMessage()); continue; }
                    foreach ($resolved as $receiver) $dispatcher->dispatch($incident, $action->destination, $action, $phase, $receiver, $message);
                    $processed++;
                }
                }
            }
        });
        $this->info("Processed {$processed} action(s)."); return self::SUCCESS;
    }

    public static function defaultTemplate(string $phase): string
    {
        return match ($phase) {
            'recovery' => "RECOVERED: Interface restored\nDevice: {{ hostname }}\nPort: {{ ifName }}\nDescription: {{ ifAlias }}\nOutage: {{ outage_duration }}\nIncident: {{ incident_id }}",
            'acknowledged' => "ACKNOWLEDGED: Interface down\nDevice: {{ hostname }}\nPort: {{ ifName }}\nBy: {{ acknowledgement_user }}\nIncident: {{ incident_id }}",
            'escalation' => "ESCALATION: Interface still down\nDevice: {{ hostname }}\nPort: {{ ifName }}\nDescription: {{ ifAlias }}\nDown since: {{ first_seen_at }}\nIncident: {{ incident_id }}",
            'reminder' => "REMINDER: Interface still down\nDevice: {{ hostname }}\nPort: {{ ifName }}\nDown since: {{ first_seen_at }}\nIncident: {{ incident_id }}",
            default => "CRITICAL: Interface down\nDevice: {{ hostname }}\nPort: {{ ifName }}\nDescription: {{ ifAlias }}\nLocation: {{ location }}\nDown since: {{ first_seen_at }}\nPolicy: {{ policy_name }}\nIncident: {{ incident_id }}",
        };
    }
}
