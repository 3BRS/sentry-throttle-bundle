<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\DependencyInjection;

use Monolog\Logger;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\Configuration;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\ThreeBRSSentryThrottleExtension;
use ThreeBRS\SentryThrottleBundle\Fingerprint\Fingerprinter;
use ThreeBRS\SentryThrottleBundle\Sentry\ThrottleBeforeSend;
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
            '$storage' => new Reference(ThreeBRSSentryThrottleExtension::STORAGE),
            '$prefix' => 'app',
            '$windowSeconds' => 30,
            '$perFingerprint' => 5,
            '$maxPerWindow' => 10,
            '$fallbackMaxPerWindow' => 3,
            '$logger' => new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
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
            '$client' => new ServiceClosureArgument(new Reference(ThreeBRSSentryThrottleExtension::REDIS)),
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
            '$client' => new ServiceClosureArgument(new Reference('app.redis')),
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

    #[RequiresMethod(Event::class, 'createEvent')]
    public function testRegistersSentryBeforeSend(): void
    {
        $container = $this->load([
            'enabled' => false,
        ]);

        $this->assertEquals([
            new Reference(ThreeBRSSentryThrottleExtension::THROTTLE),
            new Reference(ThreeBRSSentryThrottleExtension::EVENT_FINGERPRINTER),
        ], $container->getDefinition(ThrottleBeforeSend::class)->getArguments());
        $this->assertEquals([
            new Reference(ThreeBRSSentryThrottleExtension::FINGERPRINTER),
        ], $container->getDefinition(ThreeBRSSentryThrottleExtension::EVENT_FINGERPRINTER)->getArguments());
    }

    public function testDoesNotRegisterSentryServicesWithoutSentrySdk(): void
    {
        $services = explode("\n", $this->loadInSeparateProcess([
            'prefix' => 'app',
            'redis' => 'redis://localhost',
        ], ['Sentry\\']));

        $this->assertContains(ThreeBRSSentryThrottleExtension::THROTTLE, $services);
        $this->assertNotContains(ThrottleBeforeSend::class, $services);
        $this->assertNotContains(ThreeBRSSentryThrottleExtension::EVENT_FINGERPRINTER, $services);
    }

    #[RequiresMethod(Logger::class, 'pushHandler')]
    public function testWrapsConfiguredMonologHandlers(): void
    {
        $container = $this->load([
            'prefix' => 'app',
            'redis' => 'redis://localhost',
            'monolog' => [
                'handlers' => ['app.sentry_handler', 'app.sentry_handler'],
                'tags' => 'extra',
            ],
        ]);
        $id = ThreeBRSSentryThrottleExtension::MONOLOG_HANDLER . '.app.sentry_handler';

        $decorator = $container->getDefinition($id);
        $this->assertInstanceOf(ChildDefinition::class, $decorator);
        $this->assertSame(ThreeBRSSentryThrottleExtension::MONOLOG_HANDLER, $decorator->getParent());
        $this->assertSame(['app.sentry_handler', null, 0], $decorator->getDecoratedService());
        $this->assertEquals([
            '$handler' => new Reference($id . '.inner'),
            '$tagsIn' => 'extra',
        ], $decorator->getArguments());
        $this->assertCount(1, array_filter(array_keys($container->getDefinitions()), static fn (string $service): bool => str_starts_with($service, ThreeBRSSentryThrottleExtension::MONOLOG_HANDLER . '.')));
    }

    public function testDoesNotWrapMonologHandlersByDefault(): void
    {
        $this->assertFalse($this->load([
            'enabled' => false,
        ])->hasDefinition(ThreeBRSSentryThrottleExtension::MONOLOG_HANDLER));
    }

    public function testRequiresMonologForWrappedHandlers(): void
    {
        $this->assertSame('"three_brs_sentry_throttle.monolog.handlers" needs the "monolog/monolog" package.', $this->loadInSeparateProcess([
            'enabled' => false,
            'monolog' => [
                'handlers' => ['app.sentry_handler'],
            ],
        ], ['Monolog\\']));
    }

    public function testRequiresRedisClientForDsn(): void
    {
        $this->assertSame('"three_brs_sentry_throttle.redis.dsn" needs the "redis" PHP extension or the "predis/predis" package.', $this->loadInSeparateProcess([
            'prefix' => 'app',
            'redis' => 'redis://localhost',
        ], ['Predis\\'], withoutPhpIni: true));
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $hiddenNamespaces
     */
    private function loadInSeparateProcess(array $config, array $hiddenNamespaces, bool $withoutPhpIni = false): string
    {
        $command = [\PHP_BINARY, ...($withoutPhpIni ? ['-n'] : []), __DIR__ . '/load-extension.php', json_encode($config, \JSON_THROW_ON_ERROR), ...$hiddenNamespaces];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        $this->assertIsString($output);

        return $output;
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
