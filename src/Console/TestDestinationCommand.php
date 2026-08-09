<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Console;

use Illuminate\Console\Command;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Models\Destination;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\Services\NotificationDispatcher;

class TestDestinationCommand extends Command
{
    protected $signature = 'iapm:test-destination {--destination=} {--receiver=} {--force}';

    protected $description = 'Send a controlled destination test';

    public function handle(NotificationDispatcher $dispatcher): int
    {
        $destination = Destination::find($this->option('destination'));
        $receiver = trim((string) $this->option('receiver'));

        if (! $destination || $receiver === '') {
            $this->error('A valid destination and receiver are required.');

            return self::INVALID;
        }

        if (! $this->option('force') && ! $this->confirm('Send a real test notification?')) {
            return self::SUCCESS;
        }

        $message = 'IAPM test message from '.(config('app.url') ?: gethostname()).'. Destination configuration is working.';
        $result = $dispatcher->test($destination, $receiver, $message);
        $this->line($result->successful ? 'Test succeeded.' : 'Test failed: '.$result->error);

        return $result->successful ? self::SUCCESS : self::FAILURE;
    }
}
