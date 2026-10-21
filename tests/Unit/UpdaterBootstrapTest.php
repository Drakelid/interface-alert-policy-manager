<?php

namespace LibreNMS\Plugins\InterfaceAlertPolicyManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class UpdaterBootstrapTest extends TestCase
{
    public function test_the_updaters_maintenance_probe_loads_autoload_before_bootstrapping_the_app(): void
    {
        $helper = file_get_contents(dirname(__DIR__, 2).'/tools/update-production.sh');
        self::assertSame(1, preg_match(<<<'REGEX'
~state="\$\(as_librenms php -r '([^']+)'\)"~
REGEX, $helper, $matches));
        $directory = sys_get_temp_dir().'/iapm-updater-'.bin2hex(random_bytes(8));
        mkdir($directory.'/vendor', 0700, true);
        mkdir($directory.'/bootstrap', 0700, true);
        file_put_contents($directory.'/vendor/autoload.php', '<?php namespace Illuminate\\Contracts\\Console; interface Kernel {}');
        file_put_contents($directory.'/bootstrap/app.php', <<<'PHP'
<?php
return new class
{
    public function make(string $class): object
    {
        if (! interface_exists($class)) {
            throw new RuntimeException('Application booted before its autoloader.');
        }

        return new class
        {
            public function bootstrap(): void {}
        };
    }

    public function isDownForMaintenance(): bool
    {
        return getenv('FIXTURE_DOWN') === '1';
    }
};
PHP);
        try {
            foreach (['0' => 'up', '1' => 'down'] as $down => $expected) {
                $process = new Process([PHP_BINARY, '-r', $matches[1]], $directory, ['FIXTURE_DOWN' => (string) $down]);
                $process->run();

                self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                self::assertSame($expected, $process->getOutput());
            }
        } finally {
            unlink($directory.'/bootstrap/app.php');
            unlink($directory.'/vendor/autoload.php');
            rmdir($directory.'/bootstrap');
            rmdir($directory.'/vendor');
            rmdir($directory);
        }
    }
}
