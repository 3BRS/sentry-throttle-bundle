<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\ThreeBRSSentryThrottleExtension;
use ThreeBRS\SentryThrottleBundle\Fingerprint\Fingerprinter;
use ThreeBRS\SentryThrottleBundle\Storage\LazyRedisStorage;
use ThreeBRS\SentryThrottleBundle\Storage\RedisConnectionFactory;
use ThreeBRS\SentryThrottleBundle\Throttle\Throttle;
use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ThreeBRSSentryThrottleExtension::FINGERPRINTER, Fingerprinter::class)
        ->args(['%kernel.project_dir%']);

    // The class only describes the service; RedisConnectionFactory picks the client.
    $services->set(ThreeBRSSentryThrottleExtension::REDIS, \Redis::class)
        ->factory([RedisConnectionFactory::class, 'create'])
        ->args([abstract_arg('Redis DSN'), abstract_arg('connect timeout'), abstract_arg('read timeout')]);

    $services->set(ThreeBRSSentryThrottleExtension::STORAGE, LazyRedisStorage::class)
        ->args([
            '$client' => new ServiceClosureArgument(new Reference(ThreeBRSSentryThrottleExtension::REDIS)),
        ]);

    $services->set(ThreeBRSSentryThrottleExtension::THROTTLE, Throttle::class)
        ->args([
            '$storage' => service(ThreeBRSSentryThrottleExtension::STORAGE),
            '$prefix' => abstract_arg('prefix'),
            '$windowSeconds' => abstract_arg('window'),
            '$perFingerprint' => abstract_arg('per_fingerprint'),
            '$maxPerWindow' => abstract_arg('max_per_window'),
            '$fallbackMaxPerWindow' => abstract_arg('fallback_max_per_window'),
            '$logger' => service('logger')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', [
            'channel' => Throttle::LOG_CHANNEL,
        ]);
};
