<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Integration\Throttle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use ThreeBRS\SentryThrottleBundle\Storage\LazyRedisStorage;
use ThreeBRS\SentryThrottleBundle\Storage\RedisConnectionFactory;
use ThreeBRS\SentryThrottleBundle\Throttle\Throttle;

final class RedisThrottleTest extends TestCase
{
    private int $now = 1_800_000_000;

    #[DataProvider('clients')]
    public function testDrops51stIdenticalEventAndReportsDropsWithNextSentOne(string $query): void
    {
        $throttle = $this->throttle($query);

        for ($i = 0; $i < 50; ++$i) {
            $this->assertTrue($throttle->decide('a')->send);
        }
        $this->assertFalse($throttle->decide('a')->send);
        $this->assertFalse($throttle->decide('a')->send);

        $this->now += 60;
        $this->assertSame([
            'throttled' => 'true',
            'throttle_occurrences' => '2',
        ], $throttle->decide('a')->tags());
    }

    #[DataProvider('clients')]
    public function testSharesCountersBetweenProcesses(string $query): void
    {
        $prefix = $this->prefix();
        $first = $this->throttle($query, $prefix, perFingerprint: 2);
        $second = $this->throttle($query, $prefix, perFingerprint: 2);

        $this->assertTrue($first->decide('a')->send);
        $this->assertTrue($second->decide('a')->send);
        $this->assertFalse($first->decide('a')->send);

        $this->now += 60;
        $this->assertSame(1, $second->decide('a')->droppedSinceLastSent);
        $this->assertSame(0, $first->decide('a')->droppedSinceLastSent);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clients(): iterable
    {
        yield 'phpredis' => [''];
        // DSN query parameters override the factory options, so Predis is used although ext-redis is loaded.
        yield 'predis' => ['?class=' . rawurlencode(Client::class)];
    }

    private function throttle(string $query, ?string $prefix = null, int $perFingerprint = 50): Throttle
    {
        $dsn = getenv('SENTRY_THROTTLE_TEST_REDIS_DSN');
        $dsn = (\is_string($dsn) && $dsn !== '' ? $dsn : 'redis://localhost:6379') . $query;

        return new Throttle(
            new LazyRedisStorage(static fn (): object => RedisConnectionFactory::create($dsn, 1.0, 1.0)),
            $prefix ?? $this->prefix(),
            60,
            $perFingerprint,
            300,
            50,
            clock: fn (): int => $this->now,
        );
    }

    private function prefix(): string
    {
        return 'test_' . bin2hex(random_bytes(8));
    }
}
