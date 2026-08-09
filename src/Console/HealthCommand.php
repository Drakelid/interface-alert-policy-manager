<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Illuminate\Console\Command;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\HealthService;

/**
 * Dead-man's switch for external monitoring: exits non-zero when IAPM is not
 * healthy, so a NOC's own monitoring (or LibreNMS services) can alert when the
 * alerting system itself has stopped.
 */
class HealthCommand extends Command
{
    protected $signature = 'iapm:health';

    protected $description = 'Report IAPM self-monitoring health (non-zero exit when unhealthy)';

    public function handle(HealthService $health): int
    {
        $ok = true;
        foreach ($health->checks() as $check) {
            $this->line(($check['ok'] ? '[OK] ' : '[FAIL] ').$check['label'].' — '.$check['detail']);
            $ok = $ok && $check['ok'];
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
