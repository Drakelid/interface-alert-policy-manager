<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /** @var array<string, true> */
    private array $deliveredDigests = [];

    private float $deadline = 0.0;

    public function handle(NotificationDispatcher $dispatcher, ReceiverResolver $receivers, SafeTemplateRenderer $templates, SettingStore $settings, TemplateContextBuilder $placeholders, FlapDetector $flapper, MessageTemplates $messages): int
    {
        if ($this->pluginDisabled()) {
            return self::SUCCESS;
        }

        [$this->dispatcher, $this->receivers, $this->templates, $this->settings, $this->placeholders, $this->flapper, $this->messages] = [$dispatcher, $receivers, $templates, $settings, $placeholders, $flapper, $messages];

        // Wall-clock budget: stop before the next scheduler tick so a storm's backlog
        // drains across runs instead of one run overrunning the minute (see config).
        // A targeted single-incident/force run is never budget-limited; a budget of 0
        // stops immediately (test/kill-switch), otherwise a 5s floor applies.
        $budget = (int) config('iapm.processing.max_seconds', 50);
        $this->deadline = ($this->option('incident') || $this->option('force')) ? PHP_FLOAT_MAX : ($budget <= 0 ? microtime(true) : microtime(true) + max(5, $budget));

        $processed = 0;
        $targeted = (bool) ($this->option('incident') || $this->option('force'));

        // Device digest: when many interfaces on the same device trigger together,
        // send one grouped "device down" notice instead of an SMS per interface.
        // Runs before the per-incident loop so a durable in-flight digest can defer
        // individual triggers until successful delivery finalizes the episode.
        $threshold = (int) $this->settings->get('aggregate_threshold', 0);
        if ($threshold >= 2 && ! $this->option('incident') && ! $this->option('force')) {
            // Activate any due Pending incidents first, so ports that trip together on
            // a failing device are already Active (with a fresh triggered_at) and can
            // be grouped — otherwise they'd each fire an individual trigger this run.
            $this->activateEligiblePending();
            $processed += $this->emitDeviceDigests($threshold, max(30, (int) $this->settings->get('aggregate_window_seconds', 120)));
        }

        // A targeted --incident run processes exactly that incident. Otherwise take the
        // open working set plus only *recently* recovered incidents — recovered rows are
        // retained for a year, so scanning them all every minute would be crippling at
        // ISP scale. 48h is a generous window for a recovery notification (survives a
        // scheduler outage) while keeping the scan bounded to a day or two of recoveries.
        $cursor = $targeted ? 0 : (int) $this->settings->get('process_actions_cursor_id', 0);
        $lastId = $cursor;
        $stopped = false;
        Incident::query()->when($cursor > 0, fn ($q) => $q->where('id', '>', $cursor))->when($this->option('incident'),
            fn ($q, $id) => $q->whereKey($id),
            fn ($q) => $q->where(fn ($w) => $w->whereIn('state', [IncidentState::Pending, IncidentState::Active, IncidentState::Acknowledged])->orWhere(fn ($r) => $r->where('state', IncidentState::Recovered)->where('recovered_at', '>=', now()->subHours(48)))))
            ->with(['policy.actions.destination'])->chunkById((int) config('iapm.processing.batch_size', 500), function ($items) use (&$processed, &$lastId, &$stopped): bool {
                $ids = $items->pluck('id');
                $episodes = $items->pluck('episode_uuid')->filter()->unique();
                $deliveryStats = DB::table('iapm_delivery_logs')
                    ->whereIn('incident_id', $ids)->whereIn('episode_uuid', $episodes)
                    ->selectRaw("incident_id, episode_uuid, policy_action_id, phase, SUM(CASE WHEN status IN ('sent','dry_run') THEN 1 ELSE 0 END) successful_count, MAX(CASE WHEN status IN ('sent','dry_run') THEN created_at END) last_success_at, SUM(CASE WHEN status='failed_configuration' AND created_at >= ? THEN 1 ELSE 0 END) recent_config_failures", [now()->subMinutes(5)])
                    ->groupBy('incident_id', 'episode_uuid', 'policy_action_id', 'phase')->get()
                    ->keyBy(fn ($row) => $this->actionStatKey((int) $row->incident_id, (string) $row->episode_uuid, (int) $row->policy_action_id, (string) $row->phase));
                $digestInFlight = DB::table('iapm_notification_outbox_incidents as noi')
                    ->join('iapm_notification_outbox as no', 'no.id', '=', 'noi.notification_outbox_id')
                    ->whereIn('noi.incident_id', $ids)->whereIn('noi.episode_uuid', $episodes)
                    ->where('no.phase', 'digest')->whereIn('no.status', ['pending', 'queued', 'processing'])
                    ->get(['noi.incident_id', 'noi.episode_uuid'])->mapWithKeys(fn ($row) => [$row->incident_id.'|'.$row->episode_uuid => true]);
                /** @var array<string, true> $deliveredDigests */
                $deliveredDigests = DB::table('iapm_notification_outbox_incidents as noi')
                    ->join('iapm_notification_outbox as no', 'no.id', '=', 'noi.notification_outbox_id')
                    ->whereIn('noi.incident_id', $ids)->whereIn('noi.episode_uuid', $episodes)
                    ->where('no.phase', 'digest')->whereIn('no.status', ['sent', 'dry_run'])
                    ->get(['noi.incident_id', 'noi.episode_uuid', 'no.destination_id', 'no.receiver_hash'])
                    ->mapWithKeys(fn ($row) => [$row->incident_id.'|'.$row->episode_uuid.'|'.$row->destination_id.'|'.$row->receiver_hash => true])->all();
                $this->deliveredDigests = $deliveredDigests;
                $this->flapper->prime($items);
                foreach ($items as $incident) {
                    if (microtime(true) >= $this->deadline) {
                        $stopped = true;

                        return false;
                    } // out of time budget; the next scheduled run continues the backlog
                    $lastId = (int) $incident->id;
                    if ($incident->state === IncidentState::Pending && $incident->policy && $incident->first_seen_at->addSeconds($incident->policy->trigger_after_seconds)->isPast() && (int) ($incident->context_json['observation_count'] ?? 1) >= $incident->policy->down_observations) {
                        $incident->update(['state' => IncidentState::Active, 'triggered_at' => now()]);
                        $incident->events()->create(['event_type' => 'activated', 'event_message' => 'Trigger requirements satisfied.']);
                    }
                    if (! $incident->policy || ! $incident->policy->notifications_enabled) {
                        continue;
                    }
                    if ($incident->muted_until?->isFuture()) {
                        continue;
                    }

                    // Flap dampening (opt-in per policy): while an interface is flapping,
                    // send one dampened notice and suppress the routine trigger/reminder/
                    // recovery churn until it stabilises.
                    if ($this->flapper->shouldDampen($incident, $incident->policy)) {
                        if (! $this->option('force')) {
                            $processed += $this->dampenFlapping($incident);

                            continue;
                        }
                    } elseif (! empty($incident->context_json['flap_notified'])) {
                        $ctx = $incident->context_json;
                        unset($ctx['flap_notified']);
                        $incident->update(['context_json' => $ctx]);
                        $incident->events()->create(['event_type' => 'flap_cleared', 'event_message' => 'Interface stabilised; normal notifications resume.']);
                    }

                    $phases = match ($incident->state) {
                        IncidentState::Recovered => ['recovery'], IncidentState::Acknowledged => ['acknowledged'], IncidentState::Active => ['trigger', 'escalation', 'reminder'], default => []
                    };
                    foreach ($phases as $phase) {
                        if ($phase === 'recovery' && ! $incident->policy->notify_recovery) {
                            continue;
                        }
                        if ($phase === 'trigger' && $digestInFlight->has($incident->id.'|'.$incident->episode_uuid)) {
                            continue;
                        }
                        foreach ($incident->policy->actions->filter(fn ($action) => $action->enabled && $action->phase->value === $phase && (! $this->option('action') || (int) $this->option('action') === (int) $action->id)) as $action) {
                            $stat = $deliveryStats->get($this->actionStatKey((int) $incident->id, (string) $incident->episode_uuid, (int) $action->id, $phase));
                            if (! $this->option('force') && (int) ($stat->recent_config_failures ?? 0) > 0) {
                                continue;
                            }
                            $sendCount = (int) ($stat->successful_count ?? 0);
                            $lastSend = isset($stat->last_success_at) ? CarbonImmutable::parse($stat->last_success_at) : null;
                            $repeatSeconds = $action->repeat_seconds ?? (in_array($phase, ['trigger', 'reminder'], true) ? $incident->policy->repeat_seconds : null);
                            $maximumSends = $action->maximum_sends ?? ($incident->policy->maximum_repeats === null ? null : 1 + $incident->policy->maximum_repeats);
                            if (! $this->option('force') && $sendCount > 0 && ($repeatSeconds === null || ($maximumSends !== null && $sendCount >= $maximumSends))) {
                                continue;
                            }
                            if (! $this->option('force') && $lastSend && $repeatSeconds !== null && $lastSend->addSeconds($repeatSeconds)->isFuture()) {
                                continue;
                            }
                            $phaseStart = match ($phase) {
                                'recovery' => $incident->recovered_at, 'acknowledged' => $incident->acknowledged_at, default => $incident->triggered_at ?? $incident->first_seen_at
                            };
                            if (! $phaseStart || (! $this->option('force') && $phaseStart->addSeconds($action->delay_seconds)->isFuture())) {
                                continue;
                            }
                            if ($this->sendAction($incident, $action, $phase)) {
                                $processed++;
                            }
                        }
                    }
                }

                return true;
            });

        if (! $targeted) {
            $this->settings->put('process_actions_cursor_id', $stopped ? $lastId : 0);
        }
        $this->settings->put('last_process_actions_at', now()->toIso8601String());
        $this->info("Processed {$processed} action(s).");

        return self::SUCCESS;
    }

    /**
     * Promote due Pending incidents to Active (same rule the per-incident loop
     * uses) so the device digest can group ports that just tripped together.
     */
    private function activateEligiblePending(): void
    {
        Incident::query()->where('state', IncidentState::Pending)->with('policy')->orderBy('id')->limit((int) config('iapm.processing.batch_size', 500) * 2)->get()->each(function ($incident): bool {
            if (microtime(true) >= $this->deadline) {
                return false;
            }
            if ($incident->policy && $incident->first_seen_at->addSeconds($incident->policy->trigger_after_seconds)->isPast() && (int) ($incident->context_json['observation_count'] ?? 1) >= $incident->policy->down_observations) {
                $incident->update(['state' => IncidentState::Active, 'triggered_at' => now()]);
                $incident->events()->create(['event_type' => 'activated', 'event_message' => 'Trigger requirements satisfied.']);
            }

            return true;
        });
    }

    /**
     * Group active, not-yet-notified incidents by device; where a device has at
     * least $threshold that triggered within the window, send one digest instead
     * of an SMS per interface, and mark each so the per-incident loop skips it.
     */
    private function emitDeviceDigests(int $threshold, int $window): int
    {
        $cutoff = now()->subSeconds($window);
        $sent = 0;

        // Find candidate devices with a grouped count first, then load one device's
        // incidents at a time — a simultaneous multi-thousand-port event never pulls
        // every eligible incident into memory at once.
        $limit = max(1, (int) config('iapm.processing.digest_devices_per_run', 100));
        $cursor = (int) $this->settings->get('digest_cursor_device_id', 0);
        $deviceIds = $this->digestBase($cutoff)->when($cursor > 0, fn ($query) => $query->where('device_id', '>', $cursor))->select('device_id')->groupBy('device_id')->havingRaw('COUNT(*) >= ?', [$threshold])->orderBy('device_id')->limit($limit)->pluck('device_id');

        foreach ($deviceIds as $deviceId) {
            if (microtime(true) >= $this->deadline) {
                break;
            }
            $incidents = $this->digestBase($cutoff)->where('device_id', $deviceId)->with(['policy.actions.destination'])->get()
                ->filter(fn ($i) => empty($i->context_json['trigger_notified_via_digest']))
                ->values();
            if ($incidents->count() < $threshold) {
                continue;
            } // already-digested incidents dropped it below threshold
            $sent += $this->sendDeviceDigest($incidents);
            $cursor = (int) $deviceId;
        }
        $this->settings->put('digest_cursor_device_id', $deviceIds->count() < $limit ? 0 : $cursor);

        return $sent;
    }

    /**
     * Incidents eligible for device-level grouping this run: active, triggered within
     * the window, not muted, under a notifying policy, and not yet trigger-notified in
     * the current outage episode (deliveries scoped to at/after triggered_at, so a
     * device that recovers then fails again is grouped afresh rather than storming).
     */
    private function digestBase(CarbonInterface $cutoff): Builder
    {
        return Incident::query()
            ->where('state', IncidentState::Active)
            ->whereNotNull('triggered_at')->where('triggered_at', '>=', $cutoff)
            ->where(fn ($q) => $q->whereNull('muted_until')->orWhere('muted_until', '<=', now()))
            ->whereHas('policy', fn ($q) => $q->where('notifications_enabled', true))
            ->whereDoesntHave('deliveries', fn ($q) => $q->where('phase', 'trigger')->whereIn('status', ['sent', 'dry_run'])->whereColumn('iapm_delivery_logs.episode_uuid', 'iapm_incidents.episode_uuid'));
    }

    private function sendDeviceDigest(Collection $incidents): int
    {
        $first = $incidents->first();
        $ctx = (array) $first->context_json;
        $hostname = (string) (($ctx['hostname'] ?? '') ?: ('device '.$first->device_id));
        $names = $incidents->map(fn ($i) => (string) ((($i->context_json['ifName'] ?? '') ?: ('port '.$i->port_id))))->values();
        $listed = $names->take(10)->implode(', ').($names->count() > 10 ? ' +'.($names->count() - 10).' more' : '');
        $firstSeen = $incidents->min(fn ($i) => $i->first_seen_at);
        $base = rtrim((string) ($this->settings->get('url_base') ?: config('app.url', '')), '/');

        $values = [
            'severity' => $first->severity?->value ?? 'critical',
            'hostname' => $hostname,
            'device_id' => (int) $first->device_id,
            'device_groups' => $this->deviceGroupNames($ctx['deviceGroupNames'] ?? []),
            'interface_count' => $incidents->count(),
            'interfaces' => $listed,
            'first_seen_at' => $firstSeen ? $firstSeen->format('Y-m-d H:i:s') : '',
            'device_url' => $base === '' ? '' : "$base/device/{$first->device_id}",
        ];

        // One digest per distinct trigger destination across the grouped policies.
        $pairs = $incidents->flatMap(fn ($item) => $item->policy ? $item->policy->actions->filter(fn ($action) => $action->enabled && $action->phase->value === 'trigger')->map(fn ($action) => ['incident' => $item, 'action' => $action]) : collect());
        $sentAny = false;
        foreach ($pairs->groupBy(fn ($pair) => $pair['action']->destination_id) as $destinationPairs) {
            $action = $destinationPairs->first()['action'];
            $destination = $action->destination;
            if (! $destination) {
                $first->events()->create(['event_type' => 'notification_failed', 'event_message' => 'Digest destination no longer exists.', 'event_data' => ['policy_action_id' => $action->id]]);

                continue;
            }
            if (! $destination->enabled) {
                $this->dispatcher->configurationFailure($first, $destination, $action, 'digest', 'Destination is disabled.');

                continue;
            }
            // Resolve every represented incident through the normal precedence
            // chain, then deduplicate the union for this destination.
            $resolved = $destinationPairs->flatMap(fn ($pair) => $this->receivers->forAction($pair['action'], incident: $pair['incident']))->unique()->values()->all();
            if ($resolved === []) {
                $this->dispatcher->configurationFailure($first, $destination, $action, 'digest', 'No notification receiver could be resolved for the device digest.');

                continue;
            }
            try {
                $message = $this->renderMessage($this->messages->resolveDigest(), $values, $destination->type);
            } catch (\Throwable $e) {
                $this->dispatcher->configurationFailure($first, $destination, $action, 'digest', 'Digest template error: '.$e->getMessage());

                continue;
            }
            foreach ($resolved as $receiver) {
                $result = $this->dispatcher->dispatch($first, $destination, $action, 'digest', $receiver, $message, $incidents->pluck('id')->map(fn ($id) => (int) $id)->all());
                $sentAny = $sentAny || $result->successful;
            }
        }

        return $sentAny ? 1 : 0;
    }

    private function deviceGroupNames(mixed $groups): string
    {
        if (! is_array($groups)) {
            return '';
        }

        return collect($groups)
            ->filter(fn ($name) => is_scalar($name) && trim((string) $name) !== '')
            ->map(fn ($name) => trim((string) $name))
            ->unique()
            ->sort()
            ->implode(', ');
    }

    /** Send one dampened flap notice per episode via the policy's trigger actions. */
    private function dampenFlapping(Incident $incident): int
    {
        if (! empty($incident->context_json['flap_notified'])) {
            return 0;
        }

        $sent = 0;
        foreach ($incident->policy->actions->filter(fn ($a) => $a->enabled && $a->phase->value === 'trigger') as $action) {
            if ($this->sendAction($incident, $action, 'flapping', $this->messages->resolve('flapping'))) {
                $sent++;
            }
        }
        $ctx = $incident->context_json;
        $ctx['flap_notified'] = now()->toIso8601String();
        $incident->update(['context_json' => $ctx]);
        $incident->events()->create(['event_type' => 'flapping', 'event_message' => 'Interface is flapping; routine notifications are dampened until it stabilises.']);

        return $sent > 0 ? 1 : 0;
    }

    /** Resolve receivers, render, and dispatch a single action. Returns true if a send was attempted. */
    private function sendAction(Incident $incident, PolicyAction $action, string $phase, ?string $templateOverride = null): bool
    {
        $destination = $action->destination;
        if (! $destination) {
            $incident->events()->create(['event_type' => 'notification_failed', 'event_message' => ucfirst($phase).' destination no longer exists.', 'event_data' => ['policy_action_id' => $action->id]]);

            return false;
        }
        $resolved = $this->receivers->forAction($action, incident: $incident);
        if ($resolved === []) {
            $this->dispatcher->configurationFailure($incident, $destination, $action, $phase, 'No notification receiver could be resolved.');

            return false;
        }
        try {
            $message = $this->renderMessage($templateOverride ?? ($action->message_template ?: $this->messages->resolve($phase)), $this->placeholders->forIncident($incident), $destination->type);
        } catch (\Throwable $e) {
            $this->dispatcher->configurationFailure($incident, $destination, $action, $phase, 'Template error: '.$e->getMessage());

            return false;
        }
        $attempted = false;
        foreach ($resolved as $receiver) {
            if ($phase === 'trigger' && $this->digestDelivered($incident, $destination->id, $receiver)) {
                continue;
            }
            $this->dispatcher->dispatch($incident, $destination, $action, $phase, $receiver, $message);
            $attempted = true;
        }

        return $attempted;
    }

    private function renderMessage(string $template, array $values, string $destinationType): string
    {
        if ($destinationType === 'sms_gateway' && (bool) config('iapm.sms.single_segment', true)) {
            return $this->templates->renderSingleSms($template, $values);
        }

        return $this->templates->render($template, $values, (int) config('iapm.sms.message_length', 480));
    }

    private function digestDelivered(Incident $incident, int $destinationId, string $receiver): bool
    {
        return isset($this->deliveredDigests[$incident->id.'|'.$incident->episode_uuid.'|'.$destinationId.'|'.hash('sha256', $receiver)]);
    }

    private function actionStatKey(int $incidentId, string $episode, int $actionId, string $phase): string
    {
        return $incidentId.'|'.$episode.'|'.$actionId.'|'.$phase;
    }
}
