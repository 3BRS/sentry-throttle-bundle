<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\ThreeBRSSentryThrottleExtension;
use ThreeBRS\SentryThrottleBundle\Sentry\EventFingerprinter;
use ThreeBRS\SentryThrottleBundle\Sentry\ThrottleBeforeSend;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ThreeBRSSentryThrottleExtension::EVENT_FINGERPRINTER, EventFingerprinter::class)
        ->args([service(ThreeBRSSentryThrottleExtension::FINGERPRINTER)]);

    $services->set(ThrottleBeforeSend::class)
        ->args([service(ThreeBRSSentryThrottleExtension::THROTTLE), service(ThreeBRSSentryThrottleExtension::EVENT_FINGERPRINTER)]);
};
