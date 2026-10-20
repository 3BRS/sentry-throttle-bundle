<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Integration\Monolog;

use Monolog\Handler\FilterHandler;
use Monolog\Handler\TestHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\RequiresMethod;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\ThreeBRSSentryThrottleExtension;
use ThreeBRS\SentryThrottleBundle\Tests\Integration\IntegrationTestCase;

#[RequiresMethod(MonologBundle::class, 'build')]
final class MonologHandlerTest extends IntegrationTestCase
{
    private LoggerInterface $logger;

    private TestHandler $sentry;

    public function testConnectsToRedisOnFirstRecordOnly(): void
    {
        $container = $this->bootMonolog([]);

        // The logger and its handlers exist, but nothing was logged yet.
        $this->assertFalse($container->initialized(ThreeBRSSentryThrottleExtension::REDIS));

        $this->logger->error('Import failed');
        $this->assertTrue($container->initialized(ThreeBRSSentryThrottleExtension::REDIS));
    }

    public function testDrops51stIdenticalRecord(): void
    {
        $this->bootMonolog([]);

        for ($i = 1; $i <= 51; ++$i) {
            $this->logger->error('Order lookup failed', [
                'exception' => new \RuntimeException('Order ' . $i . ' not found'),
            ]);
        }

        $this->assertCount(50, $this->sentry->getRecords());
    }

    public function testTagsFirstRecordSentAfterDrops(): void
    {
        $this->bootMonolog([
            'per_fingerprint' => 1,
            'window' => 1,
        ]);

        $this->waitForNextSecond();
        $this->logger->error('Import failed');
        $this->logger->error('Import failed');
        $this->waitForNextSecond();
        $this->logger->error('Import failed');

        $records = $this->sentry->getRecords();
        $this->assertCount(2, $records);
        $this->assertIsArray($records[0]['context']);
        $this->assertArrayNotHasKey('throttled', $records[0]['context']);
        $this->assertIsArray($records[1]['context']);
        $this->assertSame('true', $records[1]['context']['throttled'] ?? null);
        $this->assertSame('1', $records[1]['context']['throttle_occurrences'] ?? null);
    }

    public function testWrapsSentryHandlerBehindFilteringHandler(): void
    {
        $this->bootMonolog([], filtered: true);

        $this->logger->warning('Import is slow');
        for ($i = 0; $i < 51; ++$i) {
            $this->logger->error('Import failed');
        }

        $this->assertCount(50, $this->sentry->getRecords());
    }

    public function testPassesEverythingWhenDisabled(): void
    {
        $this->bootMonolog([
            'enabled' => false,
        ]);

        for ($i = 0; $i < 60; ++$i) {
            $this->logger->error('Import failed');
        }

        $this->assertCount(60, $this->sentry->getRecords());
    }

    public function testRejectsUnknownHandler(): void
    {
        $this->expectException(ServiceNotFoundException::class);

        $this->bootMonolog([
            'monolog' => [
                'handlers' => ['app.missing_handler'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function bootMonolog(array $config, bool $filtered = false): ContainerInterface
    {
        $container = $this->bootKernel($config + [
            'prefix' => 'test_' . bin2hex(random_bytes(8)),
            'redis' => self::redisDsn(),
            'monolog' => [
                'handlers' => ['test.sentry_handler'],
            ],
        ], [new MonologBundle()], static function (ContainerBuilder $container) use ($filtered): void {
            $container->register('test.sentry_records', TestHandler::class)
                ->setArguments([Logger::ERROR])
                ->setPublic(true);
            // The handler a project sends to Sentry with; it passes records to the test handler.
            $container->register('test.sentry_handler', WhatFailureGroupHandler::class)
                ->setArguments([[new Reference('test.sentry_records')]]);
            $container->register('test.filtering_handler', FilterHandler::class)
                ->setArguments([new Reference('test.sentry_handler'), Logger::ERROR]);
            $container->loadFromExtension('monolog', [
                'handlers' => [
                    'sentry' => [
                        'type' => 'service',
                        'id' => $filtered ? 'test.filtering_handler' : 'test.sentry_handler',
                    ],
                ],
            ]);
        });

        $testContainer = $container->get('test.service_container');
        $this->assertInstanceOf(ContainerInterface::class, $testContainer);
        $logger = $testContainer->get('logger');
        $this->assertInstanceOf(LoggerInterface::class, $logger);
        $sentry = $container->get('test.sentry_records');
        $this->assertInstanceOf(TestHandler::class, $sentry);

        $this->logger = $logger;
        $this->sentry = $sentry;

        return $container;
    }

    /**
     * Polls the clock of the throttle: a sleep may end early, and the system clock may be adjusted meanwhile.
     */
    private function waitForNextSecond(): void
    {
        $second = time();

        while (time() === $second) {
            usleep(1_000);
        }
    }
}
