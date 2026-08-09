<?php

declare(strict_types=1);

$root = getenv('LIBRENMS_ROOT');
if (! is_string($root) || ! is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "Set LIBRENMS_ROOT to a LibreNMS checkout with installed dependencies.\n");
    exit(2);
}

require $root.'/vendor/autoload.php';
$packageLoader = require dirname(__DIR__).'/vendor/autoload.php';

// Composer prepends every newly loaded ClassLoader. Put the package loader
// behind LibreNMS so PHPUnit comes from this package while framework classes
// consistently come from the application under test.
spl_autoload_unregister([$packageLoader, 'loadClass']);
$packageLoader->register(false);

$GLOBALS['_composer_autoload_path'] = dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/vendor/phpunit/phpunit/phpunit';
