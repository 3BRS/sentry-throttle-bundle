<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\DependencyInjection;

use Predis\Client;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use ThreeBRS\SentryThrottleBundle\Fingerprint\Fingerprinter;
use ThreeBRS\SentryThrottleBundle\Storage\LazyRedisStorage;
use ThreeBRS\SentryThrottleBundle\Storage\RedisConnectionFactory;
use ThreeBRS\SentryThrottleBundle\Throttle\DisabledThrottle;
use ThreeBRS\SentryThrottleBundle\Throttle\Throttle;

final class ThreeBRSSentryThrottleExtension extends Extension
{
    public const FINGERPRINTER = 'three_brs_sentry_throttle.fingerprinter';

    public const THROTTLE = 'three_brs_sentry_throttle.throttle';

    public const STORAGE = 'three_brs_sentry_throttle.storage';

    public const REDIS = 'three_brs_sentry_throttle.redis';

    public const LOG_CHANNEL = 'sentry_throttle';

    public function getAlias(): string
    {
        return 'three_brs_sentry_throttle';
    }

    /**
     * @param array<array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{enabled: bool|string, prefix: ?string, redis: array{dsn: ?string, service: ?string, timeout: float|int|string, read_timeout: float|int|string}, window: int|string, per_fingerprint: int|string, max_per_window: int|string, fallback_max_per_window: int|string} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        if (! \is_bool($config['enabled'])) {
            throw new InvalidConfigurationException('"three_brs_sentry_throttle.enabled" cannot come from an environment variable, it decides which services are registered; use when@dev / when@test.');
        }

        $container->register(self::FINGERPRINTER, Fingerprinter::class)
            ->setArguments(['%kernel.project_dir%']);

        if (! $config['enabled']) {
            $container->register(self::THROTTLE, DisabledThrottle::class);

            return;
        }

        $container->register(self::STORAGE, LazyRedisStorage::class)
            ->setArguments([new ServiceClosureArgument($this->registerRedisClient($config['redis'], $container))]);

        $container->register(self::THROTTLE, Throttle::class)
            ->setArguments([
                new Reference(self::STORAGE),
                $config['prefix'],
                $config['window'],
                $config['per_fingerprint'],
                $config['max_per_window'],
                $config['fallback_max_per_window'],
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->addTag('monolog.logger', [
                'channel' => self::LOG_CHANNEL,
            ]);
    }

    /**
     * @param array{dsn: ?string, service: ?string, timeout: float|int|string, read_timeout: float|int|string} $redis
     */
    private function registerRedisClient(array $redis, ContainerBuilder $container): Reference
    {
        if ($redis['service'] !== null) {
            return new Reference($redis['service']);
        }

        $phpRedis = \extension_loaded('redis');

        if (! $phpRedis && ! class_exists(Client::class)) {
            throw new InvalidConfigurationException('"three_brs_sentry_throttle.redis.dsn" needs the "redis" PHP extension or the "predis/predis" package.');
        }

        // The class only describes the service; RedisConnectionFactory picks the client.
        $container->register(self::REDIS, $phpRedis ? \Redis::class : Client::class)
            ->setFactory([RedisConnectionFactory::class, 'create'])
            ->setArguments([$redis['dsn'], $redis['timeout'], $redis['read_timeout']]);

        return new Reference(self::REDIS);
    }
}
