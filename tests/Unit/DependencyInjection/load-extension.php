<?php

declare(strict_types=1);

// Loads the extension with classes of the given namespaces hidden from the autoloader; run with "php -n" to leave out
// ext-redis too. Arguments: the configuration as JSON, then the namespace prefixes. Prints the ids of the registered
// services, or the error message.

use Composer\Autoload\ClassLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\ThreeBRSSentryThrottleExtension;

/** @var ClassLoader $loader */
$loader = require \dirname(__DIR__, 3) . '/vendor/autoload.php';
$hiddenPrefixes = \array_slice($argv ?? [], 2);
$loader->unregister();
spl_autoload_register(static function (string $class) use ($loader, $hiddenPrefixes): void {
    foreach ($hiddenPrefixes as $prefix) {
        if (str_starts_with($class, $prefix)) {
            return;
        }
    }

    $loader->loadClass($class);
});

/** @var array<string, mixed> $config */
$config = json_decode($argv[1] ?? '{}', true, 512, \JSON_THROW_ON_ERROR);
$container = new ContainerBuilder();

try {
    (new ThreeBRSSentryThrottleExtension())->load([$config], $container);
    echo implode("\n", array_keys($container->getDefinitions()));
} catch (Throwable $e) {
    echo $e->getMessage();
}
