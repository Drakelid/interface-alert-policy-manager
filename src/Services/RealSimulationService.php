<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Services;

use App\Models\Port;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Enums\IncidentState;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Controllers\IngestionController;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Middleware\AuthenticateIngestion;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Http\Requests\IngestAlertRequest;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Incident;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Policy;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Simulation;

class RealSimulationService
{
    public function __construct(
        private readonly SettingStore $settings,
        private readonly AuditService $audit,
        private readonly InterfaceContextService $contexts,
        private readonly PolicyResolver $policies,
    ) {}

    public function start(
        Request $request,
        Port $port,
        int $durationSeconds,
        string $simulatedAdminStatus,
        string $simulatedOperStatus,
        bool $sendNotifications,
    ): Simulation {
        $policy = $this->assertStartable($port);
        $this->assertDuration($policy, $durationSeconds);

        $simulation = DB::transaction(function () use ($request, $port, $durationSeconds, $simulatedAdminStatus, $simulatedOperStatus, $sendNotifications): Simulation {
            $locked = Port::whereKey($port->port_id)->lockForUpdate()->firstOrFail();
            $policy = $this->assertStartable($locked);
            $this->assertDuration($policy, $durationSeconds);

            $simulation = Simulation::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $request->user()?->getAuthIdentifier(),
                'device_id' => (int) $locked->device_id,
                'port_id' => (int) $locked->port_id,
                'status' => 'starting',
                'original_admin_status' => $this->statusValue($locked->ifAdminStatus),
                'original_oper_status' => $this->statusValue($locked->ifOperStatus),
                'simulated_admin_status' => $simulatedAdminStatus,
                'simulated_oper_status' => $simulatedOperStatus,
                'duration_seconds' => $durationSeconds,
                'send_notifications' => $sendNotifications,
                'started_at' => now(),
                'recover_at' => now()->addSeconds($durationSeconds),
            ]);

            DB::table('ports')->where('port_id', $locked->port_id)->update([
                'ifAdminStatus' => $simulatedAdminStatus,
                'ifOperStatus' => $simulatedOperStatus,
            ]);

            return $simulation;
        });

        try {
            $result = $this->ingest($simulation, true);
            $incident = Incident::where('device_id', $simulation->device_id)
                ->where('port_id', $simulation->port_id)->first();
            $simulation->update([
                'status' => 'running',
                'incident_id' => $incident?->id,
                'episode_uuid' => $incident?->episode_uuid,
            ]);
            if ($sendNotifications && $incident) {
                Artisan::call('iapm:process-actions', ['--incident' => $incident->id]);
            }
            $this->audit->record($request, 'started_real_simulation', 'simulation', $simulation, null, [
                'device_id' => $simulation->device_id,
                'port_id' => $simulation->port_id,
                'duration_seconds' => $durationSeconds,
                'simulated_admin_status' => $simulatedAdminStatus,
                'simulated_oper_status' => $simulatedOperStatus,
                'send_notifications' => $sendNotifications,
                'ingestion' => $result,
            ]);

            return $simulation->fresh(['incident']) ?? $simulation;
        } catch (\Throwable $exception) {
            $this->restorePort($simulation);
            // If ingestion succeeded but action processing failed, close the
            // incident before reporting failure. Best effort only: the port is
            // already restored and normal reconciliation is the final safety net.
            try {
                $this->ingest($simulation, false);
                $incident = Incident::where('device_id', $simulation->device_id)->where('port_id', $simulation->port_id)->first();
                if ($incident) {
                    Artisan::call('iapm:process-actions', ['--incident' => $incident->id]);
                }
            } catch (\Throwable) {
            }
            $simulation->update(['status' => 'failed', 'recovered_at' => now(), 'last_error' => $this->safeError($exception)]);
            throw $exception;
        }
    }

    public function recover(Simulation $simulation, ?Request $request = null, string $reason = 'scheduled'): Simulation
    {
        if (! $this->claimRecovery($simulation)) {
            return $simulation->fresh(['incident']) ?? $simulation;
        }

        try {
            $this->restorePort($simulation);
            $result = $this->ingest($simulation, false);
            $incident = Incident::where('device_id', $simulation->device_id)
                ->where('port_id', $simulation->port_id)->first();
            if ($incident) {
                // Reconcile completes a configured recovery hold-down once it is
                // due; process-actions dispatches a configured recovery action.
                Artisan::call('iapm:reconcile', ['--incident' => $incident->id]);
                if ($simulation->send_notifications) {
                    Artisan::call('iapm:process-actions', ['--incident' => $incident->id]);
                }
            }
            $simulation->update([
                'status' => 'recovered',
                'incident_id' => $incident instanceof Incident ? $incident->id : $simulation->incident_id,
                'recovered_at' => now(),
                'last_error' => null,
            ]);
            if ($request) {
                $this->audit->record($request, 'recovered_real_simulation', 'simulation', $simulation, null, [
                    'reason' => $reason,
                    'ingestion' => $result,
                ]);
            }

            return $simulation->fresh(['incident']) ?? $simulation;
        } catch (\Throwable $exception) {
            // Port restoration is the non-negotiable part. Even if ingestion or
            // notification dispatch fails, never leave synthetic down state behind.
            $this->restorePort($simulation);
            $simulation->update(['status' => 'failed', 'recovered_at' => now(), 'last_error' => $this->safeError($exception)]);
            throw $exception;
        }
    }

    public function recoverDue(): array
    {
        $counts = ['maintained' => 0, 'recovered' => 0, 'failed' => 0];
        if (! Schema::hasTable('iapm_simulations')) {
            return $counts;
        }
        Simulation::query()->whereIn('status', ['starting', 'running', 'recovering'])
            ->orderBy('id')->chunkById(50, function ($simulations) use (&$counts): void {
                foreach ($simulations as $simulation) {
                    try {
                        if ($simulation->recover_at->isPast()) {
                            $result = $this->recover($simulation, null, 'scheduled');
                            if ($result->status === 'recovered') {
                                $counts['recovered']++;
                            }
                        } else {
                            // Polling writes the physical state back periodically.
                            // Reassert the simulation overlay until its deadline.
                            DB::table('ports')->where('port_id', $simulation->port_id)->update([
                                'ifAdminStatus' => $simulation->simulated_admin_status,
                                'ifOperStatus' => $simulation->simulated_oper_status,
                            ]);
                            if ($simulation->incident_id) {
                                Artisan::call('iapm:reconcile', ['--incident' => $simulation->incident_id]);
                                Artisan::call('iapm:process-actions', ['--incident' => $simulation->incident_id]);
                            }
                            $counts['maintained']++;
                        }
                    } catch (\Throwable) {
                        $counts['failed']++;
                    }
                }
            });
        $this->settings->put('last_simulation_maintenance_at', now()->toIso8601String());

        return $counts;
    }

    private function assertStartable(Port $port): Policy
    {
        $admin = $this->statusValue($port->ifAdminStatus);
        $oper = $this->statusValue($port->ifOperStatus);
        if ($admin !== 'up' || $oper !== 'up') {
            throw new \DomainException("The selected interface must currently be admin=up and oper=up; it is admin={$admin}, oper={$oper}.");
        }
        if ((bool) $port->ignore || (bool) $port->disabled || (bool) $port->deleted) {
            throw new \DomainException('The selected interface is ignored, disabled, or deleted in LibreNMS.');
        }
        if (Simulation::where('port_id', $port->port_id)->whereIn('status', ['starting', 'running', 'recovering'])->exists()) {
            throw new \DomainException('A real simulation is already running for this interface.');
        }
        if (Incident::where('device_id', $port->device_id)->where('port_id', $port->port_id)
            ->where('state', '!=', IncidentState::Recovered->value)->exists()) {
            throw new \DomainException('This interface already has an open IAPM incident. Recover it before starting a simulation.');
        }
        if (! filled($this->settings->get('ingestion_token'))) {
            throw new \DomainException('Generate an ingestion token before running a real simulation.');
        }
        $lastMaintenance = $this->settings->get('last_simulation_maintenance_at');
        if (! is_string($lastMaintenance) || CarbonImmutable::parse($lastMaintenance)->addMinutes(10)->isPast()) {
            throw new \DomainException('Automatic simulation recovery has not run recently. Confirm the LibreNMS scheduler is running and wait for iapm:recover-simulations before starting.');
        }
        $resolution = $this->policies->resolve($this->contexts->forPort($port), writeCache: false);
        if (! $resolution->policy) {
            throw new \DomainException('No enabled IAPM policy applies to this interface.');
        }
        if (! $resolution->policy->notifications_enabled) {
            throw new \DomainException("Policy {$resolution->policy->name} has notifications disabled.");
        }
        if (! $resolution->policy->actions()->where('phase', 'trigger')->where('enabled', true)
            ->whereHas('destination', fn ($query) => $query->where('enabled', true))->exists()) {
            throw new \DomainException("Policy {$resolution->policy->name} has no enabled trigger action and destination.");
        }

        return $resolution->policy;
    }

    private function claimRecovery(Simulation $simulation): bool
    {
        return DB::transaction(function () use ($simulation): bool {
            $locked = Simulation::whereKey($simulation->id)->lockForUpdate()->first();
            if (! $locked || in_array($locked->status, ['recovered'], true)) {
                return false;
            }
            // A recent recovering state belongs to another request. An old one
            // is a crashed recovery and may safely be retried idempotently.
            if ($locked->status === 'recovering' && $locked->updated_at?->isAfter(now()->subMinutes(2))) {
                return false;
            }
            if (! in_array($locked->status, ['starting', 'running', 'recovering', 'failed'], true)) {
                return false;
            }
            $locked->update(['status' => 'recovering']);
            $simulation->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    private function assertDuration(Policy $policy, int $durationSeconds): void
    {
        $actionDelay = (int) $policy->actions()->where('phase', 'trigger')->where('enabled', true)
            ->whereHas('destination', fn ($query) => $query->where('enabled', true))->min('delay_seconds');
        $activationDelay = max((int) $policy->trigger_after_seconds, max(0, (int) $policy->down_observations - 1) * 60);
        $minimum = $activationDelay + $actionDelay + 60;
        if ($durationSeconds < $minimum) {
            throw new \DomainException("Policy {$policy->name} needs at least {$minimum} seconds for its trigger requirements and first action; increase the simulation duration.");
        }
    }

    private function ingest(Simulation $simulation, bool $down): array
    {
        $port = Port::with('device')->findOrFail($simulation->port_id);
        $payload = [
            'alert_uid' => 'iapm-real-sim-'.$simulation->uuid,
            'device_id' => (int) $simulation->device_id,
            'hostname' => (string) $port->device?->hostname,
            'state' => $down ? 1 : 0,
            'severity' => 'critical',
            'timestamp' => now()->toIso8601String(),
            'title' => 'IAPM real simulation',
            'faults' => $down ? [[
                'port_id' => (int) $port->port_id,
                'ifName' => (string) $port->ifName,
                'ifDescr' => (string) $port->ifDescr,
                'ifAlias' => (string) $port->ifAlias,
                'ifAdminStatus' => $simulation->simulated_admin_status,
                'ifOperStatus' => $simulation->simulated_oper_status,
            ]] : [],
        ];
        $token = (string) $this->settings->get('ingestion_token');
        $ingest = IngestAlertRequest::create(
            '/plugin/interface-alert-policy-manager/api/v1/alerts',
            'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
        $ingest->setContainer(app());
        $ingest->setRedirector(app('redirect'));
        // The simulator is already durable in iapm_simulations and must recover
        // immediately rather than waiting behind an unrelated ingestion backlog.
        $ingest->attributes->set('_iapm_durable_replay', true);
        $ingest->validateResolved();
        // Exercise the bearer-token boundary too. This is an in-process request
        // to avoid a PHP-FPM worker deadlocking while it calls its own web server.
        $response = app(AuthenticateIngestion::class)->handle(
            $ingest,
            fn (IngestAlertRequest $authenticated) => app()->call([app(IngestionController::class), '__invoke'], ['request' => $authenticated])
        );
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException('IAPM ingestion returned HTTP '.$response->getStatusCode().'.');
        }
        if (! $response instanceof JsonResponse) {
            throw new \RuntimeException('IAPM ingestion returned an unexpected response type.');
        }

        return $response->getData(true);
    }

    private function restorePort(Simulation $simulation): void
    {
        DB::table('ports')->where('port_id', $simulation->port_id)->update([
            'ifAdminStatus' => $simulation->original_admin_status,
            'ifOperStatus' => $simulation->original_oper_status,
        ]);
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }

    private function safeError(\Throwable $exception): string
    {
        return mb_substr(app(Redactor::class)->text($exception->getMessage()), 0, 1000);
    }
}
