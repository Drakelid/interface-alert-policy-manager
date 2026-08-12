<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use App\Models\Port;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\Concerns\SkipsWhenPluginDisabled;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Jobs\RebuildPolicyCacheJob;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyCacheRebuilder;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;

class CacheRebuildCommand extends Command
{
    use SkipsWhenPluginDisabled;

    protected $signature = 'iapm:cache-rebuild
        {--device= : Rebuild only this LibreNMS device_id}
        {--queue : Queue a fleet-wide rebuild in worker-safe batches}';

    protected $description = 'Rebuild the materialized effective-policy cache for Interface Matrix filtering';

    public function handle(InterfaceContextService $contexts, PolicyResolver $resolver, PolicyCacheRebuilder $rebuilder): int
    {
        if ($this->pluginDisabled()) {
            return self::SUCCESS;
        }

        $device = $this->option('device');
        if ($device && $this->option('queue')) {
            $this->error('--device and --queue cannot be used together.');

            return self::INVALID;
        }
        if (! $device && $rebuilder->state()['running']) {
            $this->info('A policy cache rebuild is already running; nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            $rebuilder->markQueued();
            RebuildPolicyCacheJob::dispatch();
            $this->info('Policy cache rebuild queued.');

            return self::SUCCESS;
        }

        if (! $device) {
            $rebuilder->markQueued();
            $rebuilder->markRunning();
        }

        try {
            DB::table('iapm_interface_policy_cache')->when($device, fn ($q) => $q->whereIn('port_id', Port::where('device_id', $device)->select('port_id')))->delete();
            $count = 0;
            Port::with(['device.location', 'device.groups', 'groups'])->when($device, fn ($q, $id) => $q->where('device_id', $id))->orderBy('port_id')->chunkById((int) config('iapm.processing.batch_size', 500), function ($ports) use ($contexts, $resolver, $rebuilder, $device, &$count) {
                foreach ($ports as $port) {
                    $resolver->resolve($contexts->forPort($port));
                    $count++;
                }
                if (! $device) {
                    $rebuilder->markRunning($count);
                }
                $this->line("Resolved $count ports...");
            }, 'port_id');
        } catch (\Throwable $e) {
            if (! $device) {
                $rebuilder->markFailed(substr($e->getMessage(), 0, 500));
            }

            throw $e;
        }

        // A whole-fleet rebuild is what the matrix's staleness banner tracks. A
        // single-device rebuild leaves the rest of the cache as it was, so it
        // must not clear the warning (P1-7).
        if (! $device) {
            $rebuilder->markCompletedNow();
        }
        $this->info("Policy cache rebuilt for $count ports.");

        return self::SUCCESS;
    }
}
