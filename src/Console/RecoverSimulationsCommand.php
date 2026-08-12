<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Illuminate\Console\Command;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Console\Concerns\SkipsWhenPluginDisabled;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\RealSimulationService;

class RecoverSimulationsCommand extends Command
{
    use SkipsWhenPluginDisabled;

    protected $signature = 'iapm:recover-simulations';

    protected $description = 'Restore ports and recover real simulations whose duration has elapsed';

    public function handle(RealSimulationService $simulations): int
    {
        if ($this->pluginDisabled()) {
            return self::SUCCESS;
        }
        $counts = $simulations->recoverDue();
        $this->info("Maintained {$counts['maintained']}, recovered {$counts['recovered']}; {$counts['failed']} failed.");

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
