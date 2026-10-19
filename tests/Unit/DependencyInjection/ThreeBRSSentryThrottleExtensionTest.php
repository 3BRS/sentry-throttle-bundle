<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\Configuration;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\ThreeBRSSentryThrottleExtension;
use ThreeBRS\SentryThrottleBundle\Fingerprint\Fingerprinter;
use ThreeBRS\SentryThrottleBundle\Storage\LazyRedisStorage;
use ThreeBRS\SentryThrottleBundle\Storage\RedisConnectionFactory;
use ThreeBRS\SentryThrottleBundle\Throttle\DisabledThrottle;
use ThreeBRS\SentryThrottleBundle\Throttle\Throttle;

final class ThreeBRSSentryThrottleExtensionTest extends TestCase
{
    public function testRegistersThrottleWithConfiguredLimitsAndLogChannel(): void
    {
        $throttle = $this->load([
            'prefix' => 'app',
            'redis' => 'redis://localhost',
            'window' => 30,
            'per_fingerprint' => 5,
            'max_per_window' => 10,
            'fallback_max_per_window' => 3,
        ])->getDefinition(ThreeBRSSentryThrottleExtension::THROTTLE);

        $this->assertSame(Throttle::class, $throttle->getClass());
        $this->assertEquals([
            new Reference(ThreeBRSSentryThrottleExtension::STORAGE),
            'app',
            30,
            5,
            10,
            3,
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ], $throttle->getArguments());
        $this->assertSame([[
            'channel' => 'sentry_throttle',
        ]], $throttle->getTag('monolog.logger'));
        // Circuit breaker and fallback state must survive between Messenger messages.
        $this->assertFalse($throttle->hasTag('kernel.reset'));
    }

    public function testProvidesConfigurationForConfigDumpReference(): void
    {
        $this->assertInstanceOf(Configuration::class, (new ThreeBRSSentryThrottleExtension())->getConfiguration([], new ContainerBuilder()));
    }

    public function testCreatesRedisClientFromDsnOnFirstUse(): void
    {
        $container = $this->load([
            'prefix' => 'app',
            'redis' => 'redis://localhost',
        ]);

        $storage = $container->getDefinition(ThreeBRSSentryThrottleExtension::STORAGE);
        $this->assertSame(LazyRedisStorage::class, $storage->getClass());
        $this->assertEquals([
            new ServiceClosureArgument(new Reference(ThreeBRSSentryThrottleExtension::REDIS)),
        ], $storage->getArguments());

        $redis = $container->getDefinition(ThreeBRSSentryThrottleExtension::REDIS);
        $this->assertSame([RedisConnectionFactory::class, 'create'], $redis->getFactory());
        $this->assertSame(['redis://localhost', 0.2, 0.2], $redis->getArguments());
    }

    public function testUsesExistingRedisClientService(): void
    {
        $container = $this->load([
            'prefix' => 'app',
            'redis' => [
                'service' => 'app.redis',
            ],
        ]);

        $this->assertEquals([
            new ServiceClosureArgument(new Reference('app.redis')),
        ], $container->getDefinition(ThreeBRSSentryThrottleExtension::STORAGE)->getArguments());
        $this->assertFalse($container->hasDefinition(ThreeBRSSentryThrottleExtension::REDIS));
    }

    public function testRegistersDisabledThrottleWithoutRedis(): void
    {
        $container = $this->load([
            'enabled' => false,
        ]);

        $this->assertSame(DisabledThrottle::class, $container->getDefinition(ThreeBRSSentryThrottleExtension::THROTTLE)->getClass());
        $this->assertFalse($container->hasDefinition(ThreeBRSSentryThrottleExtension::STORAGE));
        $this->assertFalse($container->hasDefinition(ThreeBRSSentryThrottleExtension::REDIS));
    }

    public function testRegistersFingerprinterWithProjectDir(): void
    {
        $fingerprinter = $this->load([
            'enabled' => false,
        ])->getDefinition(ThreeBRSSentryThrottleExtension::FINGERPRINTER);

        $this->assertSame(Fingerprinter::class, $fingerprinter->getClass());
        $this->assertSame(['%kernel.project_dir%'], $fingerprinter->getArguments());
    }

    public function testRequiresRedisClientForDsn(): void
    {
        $process = proc_open([\PHP_BINARY, '-n', __DIR__ . '/load-without-redis-client.php'], [
            1 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        $this->assertSame('"three_brs_sentry_throttle.redis.dsn" needs the "redis" PHP extension or the "predis/predis" package.', $output);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new ThreeBRSSentryThrottleExtension())->load([$config], $container);

        return $container;
    }
}
