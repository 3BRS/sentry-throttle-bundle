<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\ThreeBRSSentryThrottleExtension;
use ThreeBRS\SentryThrottleBundle\Monolog\ThrottlingHandler;
use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    // Parent of the decorators the extension creates for the configured handlers.
    $container->services()
        ->set(ThreeBRSSentryThrottleExtension::MONOLOG_HANDLER, ThrottlingHandler::class)
        ->abstract()
        ->args([
            '$handler' => abstract_arg('wrapped handler'),
            '$throttle' => service(ThreeBRSSentryThrottleExtension::THROTTLE),
            '$fingerprinter' => service(ThreeBRSSentryThrottleExtension::FINGERPRINTER),
            '$tagsIn' => abstract_arg('record field for the tags'),
        ]);
};
