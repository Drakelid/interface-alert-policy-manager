<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Illuminate\Console\Command;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\Concerns\SkipsWhenPluginDisabled;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\PolicyAction;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\FlapDetector;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\MessageTemplates;
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

    private NotificationDispatcher $dispatcher;
    private ReceiverResolver $receivers;
    private SafeTemplateRenderer $templates;
    private SettingStore $settings;
    private TemplateContextBuilder $placeholders;
    private FlapDetector $flapper;
    private MessageTemplates $messages;

    public function handle(NotificationDispatcher $dispatcher, ReceiverResolver $receivers, SafeTemplateRenderer $templates, SettingStore $settings, TemplateContextBuilder $placeholders, FlapDetector $flapper, MessageTemplates $messages): int
    {
        if ($this->pluginDisabled()) {
            return self::SUCCESS;
        }

        [$this->dispatcher, $this->receivers, $this->templates, $this->settings, $this->placeholders, $this->flapper, $this->messages] = [$dispatcher, $receivers, $templates, $settings, $placeholders, $flapper, $messages];

        $processed = 0;
        Incident::query()->whereIn('state', [IncidentState::Pending, IncidentState::Active, IncidentState::Acknowledged, IncidentState::Recovered])->when($this->option('incident'), fn ($q, $id) => $q->whereKey($id))->with(['policy.actions.destination'])->chunkById((int) config('iapm.processing.batch_size', 500), function ($items) use (&$processed): void {
            foreach ($items as $incident) {
                if ($incident->state === IncidentState::Pending && $incident->policy && $incident->first_seen_at->addSeconds($incident->policy->trigger_after_seconds)->isPast() && (int) ($incident->context_json['observation_count'] ?? 1) >= $incident->policy->failed_poll_count) {
                    $incident->update(['state' => IncidentState::Active, 'triggered_at' => now()]);
                    $incident->events()->create(['event_type' => 'activated', 'event_message' => 'Trigger requirements satisfied.']);
                }
                if (! $incident->policy || ! $incident->policy->notifications_enabled) continue;
                if ($incident->muted_until?->isFuture()) continue;

                // Flap dampening (opt-in per policy): while an interface is flapping,
                // send one dampened notice and suppress the routine trigger/reminder/
                // recovery churn until it stabilises.
                if ($this->flapper->shouldDampen($incident, $incident->policy)) {
                    if (! $this->option('force')) {
                        $processed += $this->dampenFlapping($incident);
                        continue;
                    }
                } elseif (! empty($incident->context_json['flap_notified'])) {
                    $ctx = $incident->context_json; unset($ctx['flap_notified']); $incident->update(['context_json' => $ctx]);
                    $incident->events()->create(['event_type' => 'flap_cleared', 'event_message' => 'Interface stabilised; normal notifications resume.']);
                }

                $phases = match ($incident->state) { IncidentState::Recovered => ['recovery'], IncidentState::Acknowledged => ['acknowledged'], IncidentState::Active => ['trigger', 'escalation', 'reminder'], default => [] };
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
                    if ($this->sendAction($incident, $action, $phase)) $processed++;
                }
                }
            }
        });

        $this->settings->put('last_process_actions_at', now()->toIso8601String());
        $this->info("Processed {$processed} action(s)."); return self::SUCCESS;
    }

    /** Send one dampened flap notice per episode via the policy's trigger actions. */
    private function dampenFlapping(Incident $incident): int
    {
        if (! empty($incident->context_json['flap_notified'])) {
            return 0;
        }

        $sent = 0;
        foreach ($incident->policy->actions->filter(fn ($a) => $a->enabled && $a->phase->value === 'trigger') as $action) {
            if ($this->sendAction($incident, $action, 'flapping', $this->messages->resolve('flapping'))) $sent++;
        }
        $ctx = $incident->context_json; $ctx['flap_notified'] = now()->toIso8601String(); $incident->update(['context_json' => $ctx]);
        $incident->events()->create(['event_type' => 'flapping', 'event_message' => 'Interface is flapping; routine notifications are dampened until it stabilises.']);

        return $sent > 0 ? 1 : 0;
    }

    /** Resolve receivers, render, and dispatch a single action. Returns true if a send was attempted. */
    private function sendAction(Incident $incident, PolicyAction $action, string $phase, ?string $templateOverride = null): bool
    {
        $config = (array) $action->destination->configuration_encrypted;
        $resolved = $this->receivers->resolve((array) $action->receivers_json, (array) ($incident->context_json['assignment_receivers'] ?? []), (array) ($incident->context_json['device_group_receivers'] ?? []), [(string) ($incident->policy->default_receiver ?? '')], (array) ($config['receivers'] ?? []), [(string) ($config['default_receiver'] ?? '')], [(string) $this->settings->get('sms_default_receiver', config('iapm.sms.default_receiver'))]);
        if ($resolved === []) {
            $this->dispatcher->configurationFailure($incident, $action->destination, $action, $phase, 'No notification receiver could be resolved.');

            return false;
        }
        try {
            $message = $this->templates->render($templateOverride ?? ($action->message_template ?: $this->messages->resolve($phase)), $this->placeholders->forIncident($incident), (int) config('iapm.sms.message_length', 480));
        } catch (\Throwable $e) {
            $this->dispatcher->configurationFailure($incident, $action->destination, $action, $phase, 'Template error: '.$e->getMessage());

            return false;
        }
        foreach ($resolved as $receiver) {
            $this->dispatcher->dispatch($incident, $action->destination, $action, $phase, $receiver, $message);
        }

        return true;
    }

}
