<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use App\Models\Port;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\Concerns\SkipsWhenPluginDisabled;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\DependencyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\IncidentLifecycleService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\ReceiverResolver;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SettingStore;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\SuppressionService;

class ReconcileCommand extends Command
{
    use SkipsWhenPluginDisabled;

    protected $signature = 'iapm:reconcile {--dry-run} {--incident=} {--device=}';

    protected $description = 'Reconcile open IAPM incidents with current LibreNMS port state';

    public function handle(InterfaceContextService $contexts, PolicyResolver $resolver, SuppressionService $suppression, SettingStore $settings, DependencyResolver $dependencies, ReceiverResolver $receivers, IncidentLifecycleService $lifecycle): int
    {
        if ($this->pluginDisabled()) {
            return self::SUCCESS;
        }

        $targeted = (bool) ($this->option('incident') || $this->option('device'));
        $deadline = $targeted ? PHP_FLOAT_MAX : microtime(true) + max(5, (int) config('iapm.processing.reconcile_max_seconds', 50));
        $cursor = $targeted ? 0 : (int) $settings->get('reconcile_cursor_id', 0);
        $query = Incident::with('policy')->whereIn('state', [IncidentState::Pending, IncidentState::Active, IncidentState::Acknowledged, IncidentState::Suppressed])->when($cursor > 0, fn ($q) => $q->where('id', '>', $cursor))->when($this->option('incident'), fn ($q, $id) => $q->whereKey($id))->when($this->option('device'), fn ($q, $id) => $q->where('device_id', $id));
        $deletedPortBehavior = $settings->get('deleted_port_behavior', 'recover');
        $changed = 0;
        $failed = 0;
        $lastId = $cursor;
        $stopped = false;
        $query->chunkById((int) config('iapm.processing.batch_size', 500), function ($items) use (&$changed, &$failed, &$lastId, &$stopped, $deadline, $contexts, $resolver, $suppression, $deletedPortBehavior, $dependencies, $receivers, $lifecycle): bool {
            if (microtime(true) >= $deadline) {
                $stopped = true;

                return false;
            }
            $ports = Port::with(['device.location', 'device.groups', 'device.parents', 'groups'])->whereIn('port_id', $items->pluck('port_id'))->get()->keyBy('port_id');
            $missing = $items->filter(fn (Incident $incident): bool => ! $ports->has($incident->port_id));
            $missingRecoveredInBatch = false;
            if ($missing->isNotEmpty() && $deletedPortBehavior !== 'retain' && ! $this->option('dry-run')) {
                try {
                    $changed += $lifecycle->recoverMany($missing, 'Port no longer exists in LibreNMS.');
                    $missingRecoveredInBatch = true;
                } catch (\Throwable $exception) {
                    // Fall back to isolated recovery below. One malformed legacy
                    // row must not prevent every other incident from reconciling.
                    $this->warn('Batch recovery failed; retrying deleted ports individually: '.$exception->getMessage());
                }
            }
            foreach ($items as $incident) {
                if (microtime(true) >= $deadline) {
                    $stopped = true;

                    return false;
                }
                $lastId = (int) $incident->id;
                try {
                    if ($incident->muted_until?->isPast()) {
                        if (! $this->option('dry-run')) {
                            $incident->update(['muted_until' => null]);
                            $incident->events()->create(['event_type' => 'unmuted', 'event_message' => 'Timed mute expired during reconciliation.']);
                        } $changed++;
                    }
                    $port = $ports->get($incident->port_id);
                    if (! $port) {
                        if ($deletedPortBehavior === 'retain' || $missingRecoveredInBatch) {
                            continue;
                        }
                        $this->transition($incident, IncidentState::Recovered, 'Port no longer exists in LibreNMS.', ['recovered_at' => now()]);
                        $changed++;

                        continue;
                    }
                    $context = $contexts->forPort($port);
                    $resolution = $resolver->resolve($context, writeCache: false);
                    $policy = $resolution->policy;
                    if (! $policy) {
                        // Port recovered? Close it even without a policy, rather than re-suppress.
                        if ($context->operStatus === 'up') {
                            if (! $this->option('dry-run')) {
                                $incident->update(['policy_id' => null, 'suppression_reason' => 'no_policy']);
                            }
                            $this->transition($incident, IncidentState::Recovered, 'Port is operationally up (no effective policy).', ['recovered_at' => now(), 'suppression_reason' => null]);
                            $changed++;

                            continue;
                        }
                        // Honour an operator acknowledgement — don't bounce it back to suppressed.
                        if ($incident->state === IncidentState::Acknowledged) {
                            if (! $this->option('dry-run')) {
                                $data = (array) $incident->context_json;
                                $data['assignment_receivers'] = [];
                                $incident->update(['policy_id' => null, 'suppression_reason' => 'no_policy', 'pre_acknowledgement_state' => IncidentState::Suppressed->value, 'context_json' => $data]);
                            }

                            continue;
                        }
                        // Idempotent: only transition (and log an event) when the state actually changes,
                        // otherwise a permanently un-policied port logs a "reconciled" event every minute.
                        if ($incident->state !== IncidentState::Suppressed || $incident->suppression_reason !== 'no_policy') {
                            $this->transition($incident, IncidentState::Suppressed, 'No effective policy during reconciliation.', ['policy_id' => null, 'suppression_reason' => 'no_policy']);
                            $changed++;
                        }

                        continue;
                    }
                    $oper = $context->operStatus;
                    if ($oper === 'up') {
                        $data = $incident->context_json;
                        $upSince = isset($data['up_seen_at']) ? CarbonImmutable::parse($data['up_seen_at']) : null;
                        if (! $upSince && $policy->recovery_after_seconds > 0) {
                            $data['up_seen_at'] = now()->toIso8601String();
                            if (! $this->option('dry-run')) {
                                $incident->update(['context_json' => $data]);
                            }

                            continue;
                        }
                        $upSince ??= CarbonImmutable::now();
                        if ($upSince->addSeconds($policy->recovery_after_seconds)->isFuture()) {
                            continue;
                        }
                        $this->transition($incident, IncidentState::Recovered, 'Port is operationally up after recovery hold-down.', ['recovered_at' => now(), 'suppression_reason' => null]);
                        $changed++;

                        continue;
                    }
                    $data = array_merge((array) $incident->context_json, (array) $context);
                    unset($data['up_seen_at']);
                    // Once the policy's failed-poll threshold is met, repeated down
                    // polls convey no new lifecycle information. Cap the counter and
                    // avoid rewriting identical JSON every minute.
                    $data['observation_count'] = min((int) $policy->failed_poll_count, (int) ($data['observation_count'] ?? 0) + 1);
                    $data['assignment_receivers'] = $receivers->assignmentReceivers($resolution);
                    $data['assignment_source'] = $resolution->winner?->assignment_type->value ?? 'configured_default';
                    if ((int) $incident->policy_id !== (int) $policy->id && ! $this->option('dry-run')) {
                        $incident->update(['policy_id' => $policy->id, 'severity' => $policy->severity]);
                    }
                    // Honour an operator acknowledgement while the interface stays down —
                    // don't bounce it through suppressed/active. Recovery (port up) still clears it above.
                    if ($incident->state === IncidentState::Acknowledged) {
                        if (! $this->option('dry-run') && $data !== (array) $incident->context_json) {
                            $incident->update(['context_json' => $data]);
                        }

                        continue;
                    }
                    $reason = $suppression->reason($policy, $context, ! (bool) $port->device->status, SuppressionService::maintenanceSuppresses($port->device), SuppressionService::anyParentDown($port->device->parents), $dependencies->uplinkDown($port->device, $port->port_id));
                    if ($reason) {
                        if ($incident->state !== IncidentState::Suppressed || $incident->suppression_reason !== $reason) {
                            $this->transition($incident, IncidentState::Suppressed, "Suppressed during reconciliation: $reason", ['suppression_reason' => $reason, 'context_json' => $data]);
                            $changed++;
                        } elseif (! $this->option('dry-run') && $data !== (array) $incident->context_json) {
                            $incident->update(['context_json' => $data]);
                        }

                        continue;
                    }
                    if ($incident->state === IncidentState::Suppressed) {
                        $target = $this->requirementsMet($incident, $policy, $data['observation_count']) ? IncidentState::Active : IncidentState::Pending;
                        $this->transition($incident, $target, 'Suppression condition cleared.', ['suppression_reason' => null, 'context_json' => $data, 'triggered_at' => $target === IncidentState::Active ? ($incident->triggered_at ?? now()) : null]);
                        $changed++;

                        continue;
                    }
                    if ($incident->state === IncidentState::Pending && $this->requirementsMet($incident, $policy, $data['observation_count'])) {
                        $this->transition($incident, IncidentState::Active, 'Trigger requirements satisfied during reconciliation.', ['triggered_at' => now(), 'context_json' => $data]);
                        $changed++;
                    } elseif (! $this->option('dry-run') && $data !== (array) $incident->context_json) {
                        $incident->update(['context_json' => $data]);
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("Incident {$incident->id}: {$e->getMessage()}");
                }
            }

            return true;
        });
        if (! $this->option('dry-run')) {
            if (! $targeted) {
                $settings->put('reconcile_cursor_id', $stopped ? $lastId : 0);
            }
            $settings->put('last_reconcile_at', now()->toIso8601String());
        }
        $this->info("Reconciled; {$changed} incident(s) changed, {$failed} failed.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function requirementsMet(Incident $incident, $policy, ?int $observations = null): bool
    {
        return $incident->first_seen_at->addSeconds($policy->trigger_after_seconds)->isPast() && ($observations ?? (int) ($incident->context_json['observation_count'] ?? 1)) >= $policy->failed_poll_count;
    }

    private function transition(Incident $incident, IncidentState $state, string $message, array $attributes = []): void
    {
        if ($this->option('dry-run')) {
            $this->line("Would set incident {$incident->id} to {$state->value}: $message");

            return;
        } if ($state === IncidentState::Recovered) {
            app(IncidentLifecycleService::class)->recover($incident, $message);

            return;
        } $incident->update(array_merge($attributes, ['state' => $state]));
        $incident->events()->create(['event_type' => 'reconciled', 'event_message' => $message, 'event_data' => ['state' => $state->value]]);
    }
}
