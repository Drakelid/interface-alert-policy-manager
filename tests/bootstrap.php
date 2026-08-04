<?php

/**
 * IAPM test bootstrap.
 *
 * The `unit` suite runs without LibreNMS. The `integration` suite needs the
 * LibreNMS application, which is located via LIBRENMS_ROOT, then by looking for
 * a checkout beside the package (development) or above it (installed in vendor).
 */
$pluginAutoload = __DIR__.'/../vendor/autoload.php';
if (is_file($pluginAutoload)) {
    require_once $pluginAutoload;
}

// autoload-dev is not registered when the package is installed as a dependency
spl_autoload_register(static function (string $class): void {
    $prefix = 'LibreNMS\\Plugins\\InterfaceAlertPolicyManager\\Tests\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$root = getenv('LIBRENMS_ROOT') ?: null;
foreach ([$root, __DIR__.'/../../librenms', __DIR__.'/../../../..'] as $candidate) {
    if ($candidate && is_file($candidate.'/artisan') && is_file($candidate.'/vendor/autoload.php')) {
        $root = realpath($candidate);
        break;
    }
    $root = null;
}

if ($root === null) {
    fwrite(STDERR, "LibreNMS not found; only the 'unit' suite can run. Set LIBRENMS_ROOT to enable the 'integration' suite.\n");

    return;
}

define('IAPM_LIBRENMS_ROOT', $root);

require_once $root.'/vendor/autoload.php';
chdir($root); // LibreNMS's own bootstrap uses relative includes
require_once $root.'/tests/bootstrap.php';
