<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use App\Models\Port;
use Illuminate\Console\Command;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\InterfaceContextService;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\PolicyResolver;

class TestPolicyCommand extends Command
{
    protected $signature = 'iapm:test-policy {--port= : LibreNMS port_id}';

    protected $description = 'Explain the effective policy for an interface';

    public function handle(InterfaceContextService $contexts, PolicyResolver $resolver): int
    {
        $port = Port::query()->whereHas('device')->find($this->option('port'));
        if (! $port) {
            $this->error('Port not found.');

            return self::INVALID;
        }$r = $resolver->resolve($contexts->forPort($port));
        $this->line($r->policy ? "Effective policy: {$r->policy->name}" : 'No effective policy.');
        foreach ($r->candidates as $a) {
            $this->line("- {$a->assignment_type->value} assignment {$a->id}: {$a->policy->name}");
        }

        return $r->policy ? self::SUCCESS : self::FAILURE;
    }
}
