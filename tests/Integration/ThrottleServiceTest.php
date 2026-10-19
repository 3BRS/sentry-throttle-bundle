<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Integration;

use Monolog\Handler\TestHandler;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\ThreeBRSSentryThrottleExtension;
use ThreeBRS\SentryThrottleBundle\Storage\RedisConnectionFactory;
use ThreeBRS\SentryThrottleBundle\Throttle\DisabledThrottle;
use ThreeBRS\SentryThrottleBundle\Throttle\Throttle;
use ThreeBRS\SentryThrottleBundle\Throttle\ThrottleInterface;

final class ThrottleServiceTest extends IntegrationTestCase
{
    public function testDrops51stIdenticalEventWithRedisFromDsn(): void
    {
        $throttle = $this->throttle($this->bootKernel([
            'prefix' => $this->prefix(),
            'redis' => self::redisDsn(),
        ]));

        $this->assertInstanceOf(Throttle::class, $throttle);
        $sends = [];
        for ($i = 0; $i < 51; ++$i) {
            $sends[] = $throttle->decide('a')->send;
        }
        $this->assertSame([...array_fill(0, 50, true), false], $sends);
    }

    public function testConnectsToRedisOnFirstEvent(): void
    {
        $container = $this->bootKernel([
            'prefix' => $this->prefix(),
            'redis' => self::redisDsn(),
        ]);
        $throttle = $this->throttle($container);

        $this->assertFalse($container->initialized(ThreeBRSSentryThrottleExtension::REDIS));

        $throttle->decide('a');
        $this->assertTrue($container->initialized(ThreeBRSSentryThrottleExtension::REDIS));
    }

    public function testUsesExistingRedisClientService(): void
    {
        $container = $this->bootKernel([
            'prefix' => $this->prefix(),
            'redis' => [
                'service' => 'app.redis',
            ],
            'per_fingerprint' => 1,
        ], [], static function (ContainerBuilder $container): void {
            $container->register('app.redis', \Redis::class)
                ->setFactory([RedisConnectionFactory::class, 'create'])
                ->setArguments([self::redisDsn(), 1.0, 1.0]);
        });
        $throttle = $this->throttle($container);

        $this->assertTrue($throttle->decide('a')->send);
        $this->assertFalse($throttle->decide('a')->send);
        $this->assertFalse($container->has(ThreeBRSSentryThrottleExtension::REDIS));
    }

    public function testRejectsUnknownRedisClientService(): void
    {
        $this->expectException(ServiceNotFoundException::class);

        $this->bootKernel([
            'prefix' => 'app',
            'redis' => [
                'service' => 'app.missing',
            ],
        ]);
    }

    public function testSendsEverythingWithoutRedisWhenDisabled(): void
    {
        $container = $this->bootKernel([
            'enabled' => false,
        ]);
        $throttle = $this->throttle($container);
        $sends = [];

        for ($i = 0; $i < 100; ++$i) {
            $sends[] = $throttle->decide('a')->send;
        }

        $this->assertInstanceOf(DisabledThrottle::class, $throttle);
        $this->assertNotContains(false, $sends);
        $this->assertFalse($container->has(ThreeBRSSentryThrottleExtension::REDIS));
    }

    public function testRejectsEnabledFromEnvironmentVariable(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"three_brs_sentry_throttle.enabled" cannot come from an environment variable');

        $this->bootKernel([
            'enabled' => '%env(bool:SENTRY_THROTTLE_TEST_ENABLED)%',
            'prefix' => 'app',
            'redis' => self::redisDsn(),
        ]);
    }

    public function testAcceptsEnvironmentVariables(): void
    {
        $variables = [
            'SENTRY_THROTTLE_TEST_PREFIX' => $this->prefix(),
            'SENTRY_THROTTLE_TEST_DSN' => self::redisDsn(),
            'SENTRY_THROTTLE_TEST_TIMEOUT' => '1.0',
            'SENTRY_THROTTLE_TEST_WINDOW' => '60',
            'SENTRY_THROTTLE_TEST_PER_FINGERPRINT' => '1',
        ];
        $_SERVER = $variables + $_SERVER;

        try {
            $throttle = $this->throttle($this->bootKernel([
                'prefix' => '%env(SENTRY_THROTTLE_TEST_PREFIX)%',
                'redis' => [
                    'dsn' => '%env(SENTRY_THROTTLE_TEST_DSN)%',
                    'timeout' => '%env(float:SENTRY_THROTTLE_TEST_TIMEOUT)%',
                ],
                'window' => '%env(int:SENTRY_THROTTLE_TEST_WINDOW)%',
                'per_fingerprint' => '%env(int:SENTRY_THROTTLE_TEST_PER_FINGERPRINT)%',
            ]));

            $this->assertTrue($throttle->decide('a')->send);
            $this->assertFalse($throttle->decide('a')->send);
        } finally {
            foreach (array_keys($variables) as $name) {
                unset($_SERVER[$name]);
            }
        }
    }

    public function testLogsStorageFailureToSentryThrottleChannel(): void
    {
        if (! class_exists(MonologBundle::class)) {
            $this->markTestSkipped('symfony/monolog-bundle is not installed.');
        }

        $container = $this->bootKernel([
            'prefix' => 'app',
            'redis' => [
                // TEST-NET-1 (RFC 5737) is never routed, so the connection attempt gets no answer.
                'dsn' => 'redis://192.0.2.1:6379',
                'timeout' => 0.2,
                'read_timeout' => 0.2,
            ],
        ], [new MonologBundle()], static function (ContainerBuilder $container): void {
            $container->register('test.log_handler', TestHandler::class)->setPublic(true);
            $container->loadFromExtension('monolog', [
                'handlers' => [
                    'test' => [
                        'type' => 'service',
                        'id' => 'test.log_handler',
                        'channels' => [ThreeBRSSentryThrottleExtension::LOG_CHANNEL],
                    ],
                ],
            ]);
        });

        $this->assertTrue($this->throttle($container)->decide('a')->send);

        $handler = $container->get('test.log_handler');
        $this->assertInstanceOf(TestHandler::class, $handler);
        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('sentry_throttle', $records[0]['channel']);
        $this->assertSame('WARNING', $records[0]['level_name']);
    }

    private function throttle(ContainerInterface $container): ThrottleInterface
    {
        $throttle = $container->get(ThreeBRSSentryThrottleExtension::THROTTLE);
        $this->assertInstanceOf(ThrottleInterface::class, $throttle);

        return $throttle;
    }

    private function prefix(): string
    {
        return 'test_' . bin2hex(random_bytes(8));
    }
}
