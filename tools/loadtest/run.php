<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use LibreNMS\Plugins\InterfaceAlertPolicyManager\IapmServiceProvider;

$root = getenv('LIBRENMS_ROOT');
if (! is_string($root) || ! is_file($root.'/bootstrap/app.php') || ! is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "Set LIBRENMS_ROOT to a LibreNMS checkout with installed dependencies.\n");
    exit(2);
}

require $root.'/vendor/autoload.php';
$prefix = 'LibreNMS\\Plugins\\InterfaceAlertPolicyManager\\';
spl_autoload_register(static function (string $class) use ($prefix): void {
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__, 2).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($path)) {
        require_once $path;
    }
});

chdir($root);
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->register(IapmServiceProvider::class);

$operation = $argv[1] ?? '';
$script = match ($operation) {
    'seed' => __DIR__.'/seed.php',
    'measure' => __DIR__.'/loadtest.php',
    'cleanup' => __DIR__.'/cleanup.php',
    default => null,
};
if ($script === null) {
    fwrite(STDERR, "Usage: php tools/loadtest/run.php seed|measure|cleanup\n");
    exit(2);
}

try {
    require $script;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
