<?php

declare(strict_types=1);

// Run with "php -n", so that ext-redis is not loaded; Predis is hidden from the autoloader. Loads the extension with
// a DSN and prints the error message.

use Composer\Autoload\ClassLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\ThreeBRSSentryThrottleExtension;

/** @var ClassLoader $loader */
$loader = require \dirname(__DIR__, 3) . '/vendor/autoload.php';
$loader->unregister();
spl_autoload_register(static function (string $class) use ($loader): void {
    if (! str_starts_with($class, 'Predis\\')) {
        $loader->loadClass($class);
    }
});

try {
    (new ThreeBRSSentryThrottleExtension())->load([[
        'prefix' => 'app',
        'redis' => 'redis://localhost',
    ]], new ContainerBuilder());
    echo 'Loaded.';
} catch (Throwable $e) {
    echo $e->getMessage();
}
