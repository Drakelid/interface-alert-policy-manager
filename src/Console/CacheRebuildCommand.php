<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use App\Models\Port;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;

class CacheRebuildCommand extends Command
{
    protected $signature = 'iapm:cache-rebuild {--device=}';

    protected $description = 'Rebuild the materialized effective-policy cache for Interface Matrix filtering';

    public function handle(InterfaceContextService $contexts, PolicyResolver $resolver): int
    {
        DB::table('iapm_interface_policy_cache')->when($this->option('device'), fn ($q) => $q->whereIn('port_id', Port::where('device_id', $this->option('device'))->select('port_id')))->delete();
        $count = 0;
        Port::with(['device.location', 'device.groups', 'groups'])->when($this->option('device'), fn ($q, $id) => $q->where('device_id', $id))->orderBy('port_id')->chunkById((int) config('iapm.processing.batch_size', 500), function ($ports) use ($contexts, $resolver, &$count) {
            foreach ($ports as $port) {
                $resolver->resolve($contexts->forPort($port));
                $count++;
            }$this->line("Resolved $count ports...");
        }, 'port_id');
        $this->info("Policy cache rebuilt for $count ports.");

        return self::SUCCESS;
    }
}
