<?php

/**
 * IAPM test bootstrap.
 *
 * The `unit` suite runs without LibreNMS. The `integration` suite needs the
 * LibreNMS application, which is located via LIBRENMS_ROOT, then by looking for
 * a checkout beside the package (development) or above it (installed in vendor).
 */
$root = getenv('LIBRENMS_ROOT') ?: null;
foreach ([$root, __DIR__.'/../../librenms', __DIR__.'/../../../..'] as $candidate) {
    if ($candidate && is_file($candidate.'/artisan') && is_file($candidate.'/vendor/autoload.php')) {
        $root = realpath($candidate);
        break;
    }
    $root = null;
}

if ($root === null) {
    $pluginAutoload = __DIR__.'/../vendor/autoload.php';
    if (is_file($pluginAutoload)) {
        require_once $pluginAutoload;
    }
    fwrite(STDERR, "LibreNMS not found; only the 'unit' suite can run. Set LIBRENMS_ROOT to enable the 'integration' suite.\n");

    return;
}

define('IAPM_LIBRENMS_ROOT', $root);

// Integration tests register this package after the LibreNMS application has
// booted. Force normal route loading so late package routes share the core
// collection. A normal Composer installation discovers the provider before
// route caching and does not need this test-only override.
putenv('APP_ROUTES_CACHE='.sys_get_temp_dir().'/iapm-test-routes-not-cached.php');

require_once $root.'/vendor/autoload.php';

// A bind-mounted package is not in LibreNMS's Composer map. Register only the
// package namespaces here; loading the package's separate vendor tree would put
// two Laravel installations in one PHPUnit process.
spl_autoload_register(static function (string $class): void {
    $testPrefix = 'LibreNMS\\Plugins\\InterfaceAlertPolicyManager\\Tests\\';
    $sourcePrefix = 'LibreNMS\\Plugins\\InterfaceAlertPolicyManager\\';
    if (str_starts_with($class, $testPrefix)) {
        $path = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen($testPrefix))).'.php';
    } elseif (str_starts_with($class, $sourcePrefix)) {
        $path = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($sourcePrefix))).'.php';
    } else {
        return;
    }

    if (is_file($path)) {
        require_once $path;
    }
});

chdir($root); // LibreNMS's own bootstrap uses relative includes
require_once $root.'/tests/bootstrap.php';
