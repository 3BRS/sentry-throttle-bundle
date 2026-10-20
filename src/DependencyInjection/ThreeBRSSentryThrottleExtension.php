<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\DependencyInjection;

use Monolog\Handler\HandlerInterface;
use Predis\Client;
use Sentry\Event;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use ThreeBRS\SentryThrottleBundle\Throttle\DisabledThrottle;

final class ThreeBRSSentryThrottleExtension extends Extension
{
    public const FINGERPRINTER = 'three_brs_sentry_throttle.fingerprinter';

    public const THROTTLE = 'three_brs_sentry_throttle.throttle';

    public const STORAGE = 'three_brs_sentry_throttle.storage';

    public const REDIS = 'three_brs_sentry_throttle.redis';

    public const EVENT_FINGERPRINTER = 'three_brs_sentry_throttle.sentry.event_fingerprinter';

    public const MONOLOG_HANDLER = 'three_brs_sentry_throttle.monolog.throttling_handler';

    public function getAlias(): string
    {
        return 'three_brs_sentry_throttle';
    }

    /**
     * @param array<mixed> $config
     */
    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        return new Configuration($container);
    }

    /**
     * @param array<array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{enabled: bool|string, prefix: ?string, redis: array{dsn: ?string, service: ?string, timeout: float|int|string, read_timeout: float|int|string}, monolog: array{handlers: list<string>, tags: string}, window: int|string, per_fingerprint: int|string, max_per_window: int|string, fallback_max_per_window: int|string} $config */
        $config = $this->processConfiguration($this->getConfiguration($configs, $container), $configs);

        if (! \is_bool($config['enabled'])) {
            throw new InvalidConfigurationException('"three_brs_sentry_throttle.enabled" cannot come from an environment variable, it decides which services are registered; use when@dev / when@test.');
        }

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');

        if (class_exists(Event::class)) {
            $loader->load('sentry.php');
        }

        if ($config['monolog']['handlers'] !== []) {
            $this->wrapMonologHandlers($config['monolog'], $loader, $container);
        }

        if (! $config['enabled']) {
            $container->register(self::THROTTLE, DisabledThrottle::class);
            $container->removeDefinition(self::STORAGE);
            $container->removeDefinition(self::REDIS);

            return;
        }

        $container->getDefinition(self::THROTTLE)
            ->setArgument('$prefix', $config['prefix'])
            ->setArgument('$windowSeconds', $config['window'])
            ->setArgument('$perFingerprint', $config['per_fingerprint'])
            ->setArgument('$maxPerWindow', $config['max_per_window'])
            ->setArgument('$fallbackMaxPerWindow', $config['fallback_max_per_window']);

        $this->configureRedisClient($config['redis'], $container);
    }

    /**
     * @param array{handlers: list<string>, tags: string} $monolog
     */
    private function wrapMonologHandlers(array $monolog, PhpFileLoader $loader, ContainerBuilder $container): void
    {
        if (! interface_exists(HandlerInterface::class)) {
            throw new InvalidConfigurationException('"three_brs_sentry_throttle.monolog.handlers" needs the "monolog/monolog" package.');
        }

        $loader->load('monolog.php');

        foreach (array_unique($monolog['handlers']) as $handler) {
            $id = self::MONOLOG_HANDLER . '.' . $handler;
            $container->setDefinition($id, new ChildDefinition(self::MONOLOG_HANDLER))
                ->setDecoratedService($handler)
                ->setArgument('$handler', new Reference($id . '.inner'))
                ->setArgument('$tagsIn', $monolog['tags']);
        }
    }

    /**
     * @param array{dsn: ?string, service: ?string, timeout: float|int|string, read_timeout: float|int|string} $redis
     */
    private function configureRedisClient(array $redis, ContainerBuilder $container): void
    {
        if ($redis['service'] !== null) {
            $container->removeDefinition(self::REDIS);
            $container->getDefinition(self::STORAGE)
                ->setArgument('$client', new ServiceClosureArgument(new Reference($redis['service'])))
                ->setArgument('$sharedClient', true);

            return;
        }

        $phpRedis = \extension_loaded('redis');

        if (! $phpRedis && ! class_exists(Client::class)) {
            throw new InvalidConfigurationException('"three_brs_sentry_throttle.redis.dsn" needs the "redis" PHP extension or the "predis/predis" package.');
        }

        $container->getDefinition(self::REDIS)
            ->setClass($phpRedis ? \Redis::class : Client::class)
            ->setArguments([$redis['dsn'], $redis['timeout'], $redis['read_timeout']]);
    }
}
