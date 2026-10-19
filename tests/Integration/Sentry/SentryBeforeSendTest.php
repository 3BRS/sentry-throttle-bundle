<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Integration\Sentry;

use Monolog\Logger;
use PHPUnit\Framework\Attributes\RequiresMethod;
use Sentry\Event;
use Sentry\Monolog\Handler;
use Sentry\SentryBundle\SentryBundle;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\ThreeBRSSentryThrottleExtension;
use ThreeBRS\SentryThrottleBundle\Sentry\ThrottleBeforeSend;
use ThreeBRS\SentryThrottleBundle\Tests\Integration\IntegrationTestCase;

#[RequiresMethod(SentryBundle::class, 'build')]
final class SentryBeforeSendTest extends IntegrationTestCase
{
    private HubInterface $hub;

    private SpyTransport $transport;

    protected function tearDown(): void
    {
        // sentry-symfony binds its client to the global hub.
        SentrySdk::init();

        parent::tearDown();
    }

    public function testConnectsToRedisOnFirstEventOnly(): void
    {
        $container = $this->bootSentry([]);

        // The hub and its client with the before_send callback exist, but nothing was captured yet.
        $this->assertFalse($container->initialized(ThreeBRSSentryThrottleExtension::REDIS));

        $this->hub->captureMessage('Import failed');
        $this->assertTrue($container->initialized(ThreeBRSSentryThrottleExtension::REDIS));
    }

    public function testDrops51stIdenticalEvent(): void
    {
        $this->bootSentry([]);

        for ($i = 1; $i <= 51; ++$i) {
            $this->hub->captureException(new \RuntimeException('Order ' . $i . ' not found'));
        }

        $this->assertCount(50, $this->transport->events);
    }

    public function testTagsFirstEventSentAfterDrops(): void
    {
        $this->bootSentry([
            'per_fingerprint' => 1,
            'window' => 1,
        ]);

        $this->waitForNextSecond();
        $this->hub->captureMessage('Import failed');
        $this->hub->captureMessage('Import failed');
        $this->waitForNextSecond();
        $this->hub->captureMessage('Import failed');

        $this->assertCount(2, $this->transport->events);
        $this->assertArrayNotHasKey('throttled', $this->transport->events[0]->getTags());
        $this->assertSame('true', $this->transport->events[1]->getTags()['throttled'] ?? null);
        $this->assertSame('1', $this->transport->events[1]->getTags()['throttle_occurrences'] ?? null);
    }

    public function testSendsEverythingWhenDisabled(): void
    {
        $this->bootSentry([
            'enabled' => false,
        ]);

        for ($i = 0; $i < 60; ++$i) {
            $this->hub->captureMessage('Import failed');
        }

        $this->assertCount(60, $this->transport->events);
    }

    #[RequiresMethod(MonologBundle::class, 'build')]
    public function testDoesNotSendFailOpenLogThroughSentryMonologHandler(): void
    {
        $this->bootSentry([
            'redis' => [
                // TEST-NET-1 (RFC 5737) is never routed, so the connection attempt gets no answer.
                'dsn' => 'redis://192.0.2.1:6379',
                'timeout' => 0.2,
                'read_timeout' => 0.2,
            ],
        ], [new MonologBundle()], static function (ContainerBuilder $container): void {
            $container->register(Handler::class, Handler::class)
                ->setArguments([new Reference(HubInterface::class), Logger::WARNING]);
            $container->loadFromExtension('monolog', [
                'handlers' => [
                    'sentry' => [
                        'type' => 'service',
                        'id' => Handler::class,
                    ],
                ],
            ]);
        });

        $this->hub->captureMessage('Import failed');

        $this->assertCount(1, $this->transport->events);
        $this->assertSame('Import failed', $this->transport->events[0]->getMessage());
    }

    public function testCountsOnlyEventsLeftByOwnBeforeSend(): void
    {
        $this->bootSentry([], configure: static function (ContainerBuilder $container): void {
            $container->register('test.own_before_send', OwnBeforeSend::class)->setAutowired(true);
        }, beforeSend: 'test.own_before_send');

        for ($i = 0; $i < 100; ++$i) {
            $this->hub->captureMessage('Ignored');
        }
        for ($i = 0; $i < 51; ++$i) {
            $this->hub->captureMessage('Import failed');
        }

        $this->assertCount(50, $this->transport->events);
        $this->assertSame(['Import failed'], array_values(array_unique(array_map(static fn (Event $event): ?string => $event->getMessage(), $this->transport->events))));
    }

    /**
     * @param array<string, mixed> $config
     * @param list<BundleInterface> $bundles
     * @param (\Closure(ContainerBuilder): void)|null $configure
     */
    private function bootSentry(array $config, array $bundles = [], ?\Closure $configure = null, string $beforeSend = ThrottleBeforeSend::class): ContainerInterface
    {
        $container = $this->bootKernel($config + [
            'prefix' => 'test_' . bin2hex(random_bytes(8)),
            'redis' => self::redisDsn(),
        ], [new SentryBundle(), ...$bundles], static function (ContainerBuilder $container) use ($configure, $beforeSend): void {
            $container->register('test.sentry_transport', SpyTransport::class)->setPublic(true);
            // Otherwise sentry-symfony 5.0 reads the release through jean85/pretty-package-versions 1.x, which fails
            // without the composer/package-versions-deprecated plugin.
            $container->setParameter('env(SENTRY_RELEASE)', 'test');
            $container->loadFromExtension('sentry', [
                'dsn' => 'http://public@example.com/1',
                'register_error_listener' => false,
                'register_error_handler' => false,
                'options' => [
                    'before_send' => $beforeSend,
                    'transport' => 'test.sentry_transport',
                ],
            ]);

            if ($configure instanceof \Closure) {
                $configure($container);
            }
        });

        $testContainer = $container->get('test.service_container');
        $this->assertInstanceOf(ContainerInterface::class, $testContainer);
        $hub = $testContainer->get(HubInterface::class);
        $this->assertInstanceOf(HubInterface::class, $hub);
        $transport = $container->get('test.sentry_transport');
        $this->assertInstanceOf(SpyTransport::class, $transport);

        $this->hub = $hub;
        $this->transport = $transport;

        return $container;
    }

    private function waitForNextSecond(): void
    {
        usleep((int) ((1 - fmod(microtime(true), 1)) * 1_000_000) + 10_000);
    }
}
