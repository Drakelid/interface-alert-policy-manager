<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyCacheRebuilder;

class CacheClearCommand extends Command
{
    protected $signature = 'iapm:cache-clear';

    protected $description = 'Clear IAPM policy-resolution caches';

    public function handle(): int
    {
        Cache::forget('iapm.policy-resolution');
        if (Schema::hasTable('iapm_interface_policy_cache')) {
            PolicyCacheRebuilder::invalidate();
        }$this->info('IAPM policy caches cleared.');

        return self::SUCCESS;
    }
}
