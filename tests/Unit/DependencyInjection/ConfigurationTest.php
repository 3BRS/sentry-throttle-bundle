<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    public function testHasDefaults(): void
    {
        $config = $this->process([
            'prefix' => 'app',
            'redis' => 'redis://localhost',
        ]);

        $this->assertTrue($config['enabled']);
        $this->assertSame([
            'dsn' => 'redis://localhost',
            'service' => null,
            'timeout' => 0.2,
            'read_timeout' => 0.2,
        ], $config['redis']);
        $this->assertSame([
            'handlers' => [],
            'tags' => 'context',
        ], $config['monolog']);
        $this->assertSame(60, $config['window']);
        $this->assertSame(50, $config['per_fingerprint']);
        $this->assertSame(300, $config['max_per_window']);
        $this->assertSame(50, $config['fallback_max_per_window']);
    }

    public function testAcceptsExistingRedisService(): void
    {
        $config = $this->process([
            'prefix' => 'app',
            'redis' => [
                'service' => 'app.redis',
            ],
        ]);

        $this->assertNull($config['redis']['dsn']);
        $this->assertSame('app.redis', $config['redis']['service']);
    }

    public function testAcceptsIntegerTimeouts(): void
    {
        $config = $this->process([
            'prefix' => 'app',
            'redis' => [
                'dsn' => 'redis://localhost',
                'timeout' => 1,
                'read_timeout' => 2,
            ],
        ]);

        // Kept as integers; PHP converts them to float when they are passed to RedisConnectionFactory.
        $this->assertSame(1, $config['redis']['timeout']);
        $this->assertSame(2, $config['redis']['read_timeout']);
    }

    public function testAcceptsSingleMonologHandler(): void
    {
        $this->assertSame(['app.sentry_handler'], $this->process([
            'enabled' => false,
            'monolog' => [
                'handlers' => 'app.sentry_handler',
            ],
        ])['monolog']['handlers']);
    }

    public function testDisabledThrottleNeedsNeitherPrefixNorRedis(): void
    {
        $this->assertFalse($this->process([
            'enabled' => false,
        ])['enabled']);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    public function testRejectsInvalidConfiguration(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        $valid = [
            'prefix' => 'app',
            'redis' => 'redis://localhost',
        ];

        yield 'missing prefix' => [[
            'redis' => 'redis://localhost',
        ], '"prefix" must be set when the throttle is enabled.'];
        yield 'empty prefix' => [[
            'prefix' => '',
        ] + $valid, '"prefix" must be set when the throttle is enabled.'];
        yield 'missing redis' => [[
            'prefix' => 'app',
        ], '"redis" must be set when the throttle is enabled.'];
        yield 'empty dsn' => [[
            'redis' => '',
        ] + $valid, 'The path "three_brs_sentry_throttle.redis.dsn" cannot contain an empty value'];
        yield 'empty service' => [[
            'redis' => [
                'service' => '',
            ],
        ] + $valid, 'The path "three_brs_sentry_throttle.redis.service" cannot contain an empty value'];
        yield 'unknown tag field' => [[
            'monolog' => [
                'tags' => 'tags',
            ],
        ] + $valid, 'The value "tags" is not allowed for path "three_brs_sentry_throttle.monolog.tags"'];
        yield 'dsn and service' => [[
            'redis' => [
                'dsn' => 'redis://localhost',
                'service' => 'app.redis',
            ],
        ] + $valid, 'Set either "dsn" or "service", not both.'];
        yield 'zero timeout' => [[
            'redis' => [
                'dsn' => 'redis://localhost',
                'timeout' => 0,
            ],
        ] + $valid, '"redis.timeout" must be greater than 0.'];
        yield 'zero read timeout' => [[
            'redis' => [
                'dsn' => 'redis://localhost',
                'read_timeout' => 0,
            ],
        ] + $valid, '"redis.read_timeout" must be greater than 0.'];

        foreach (['window', 'per_fingerprint', 'max_per_window', 'fallback_max_per_window'] as $option) {
            yield 'zero ' . $option => [[
                $option => 0,
            ] + $valid, '"' . $option . '" must be at least 1.'];
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
