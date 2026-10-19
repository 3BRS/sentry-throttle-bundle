<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Throttle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use ThreeBRS\SentryThrottleBundle\Storage\LazyRedisStorage;
use ThreeBRS\SentryThrottleBundle\Storage\RedisConnectionFactory;
use ThreeBRS\SentryThrottleBundle\Throttle\Throttle;

final class UnreachableRedisThrottleTest extends TestCase
{
    #[DataProvider('clients')]
    public function testFailsOpenWithinTimeoutWhenConnectionIsNotAnswered(string $query): void
    {
        // TEST-NET-1 (RFC 5737) is never routed, so the connection attempt gets no answer.
        $this->assertFailsOpenWithinTimeout('redis://192.0.2.1:6379' . $query);
    }

    #[DataProvider('clients')]
    public function testFailsOpenWithinTimeoutWhenCommandIsNotAnswered(string $query): void
    {
        // The kernel accepts connections into the backlog, but nothing ever reads or replies.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $address = stream_socket_get_name($server, false);
        $this->assertNotFalse($address);

        $this->assertFailsOpenWithinTimeout('redis://' . $address . $query);
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

    private function assertFailsOpenWithinTimeout(string $dsn): void
    {
        $throttle = new Throttle(
            new LazyRedisStorage(static fn (): object => RedisConnectionFactory::create($dsn, 0.2, 0.2)),
            'app',
            60,
            50,
            300,
            3,
            clock: static fn (): int => 1_800_000_000,
        );
        $start = microtime(true);
        $sends = [];

        for ($i = 0; $i < 10; ++$i) {
            $sends[] = $throttle->decide('a')->send;
        }

        // One connection attempt per window: the storage is not used again after it fails.
        $this->assertLessThan(1.0, microtime(true) - $start);
        $this->assertSame([true, true, true, false, false, false, false, false, false, false], $sends);
    }
}
