<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use ThreeBRS\SentryThrottleBundle\Storage\LazyRedisStorage;
use ThreeBRS\SentryThrottleBundle\Storage\Operation;
use ThreeBRS\SentryThrottleBundle\Storage\RedisConnectionFactory;

final class UnreachableRedisTest extends TestCase
{
    private const TIMEOUT = 0.2;

    /**
     * Without the timeouts the client would wait for the defaults (30 s connect, 60 s read).
     */
    private const MAX_SECONDS = 1.0;

    #[DataProvider('clients')]
    public function testFailsWithinTimeoutWhenConnectionIsNotAnswered(string $query): void
    {
        // TEST-NET-1 (RFC 5737) is never routed, so the connection attempt gets no answer.
        $this->assertFailsWithinTimeout('redis://192.0.2.1:6379' . $query);
    }

    #[DataProvider('clients')]
    public function testFailsWithinTimeoutWhenCommandIsNotAnswered(string $query): void
    {
        // The kernel accepts connections into the backlog, but nothing ever reads or replies.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $address = stream_socket_get_name($server, false);
        $this->assertNotFalse($address);

        $this->assertFailsWithinTimeout('redis://' . $address . $query);
    }

    #[DataProvider('clients')]
    public function testTimeoutsInDsnOverrideOptions(string $query): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $address = stream_socket_get_name($server, false);
        $this->assertNotFalse($address);
        $timeouts = ($query === '' ? '?' : $query . '&') . 'timeout=' . self::TIMEOUT . '&read_timeout=' . self::TIMEOUT;

        // A connect timeout for an address that does not answer, a read timeout for a server that does not reply.
        foreach (['192.0.2.1:6379', $address] as $target) {
            $this->assertFailsWithinTimeout('redis://' . $target . $timeouts, timeout: 5.0);
        }
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

    private function assertFailsWithinTimeout(string $dsn, float $timeout = self::TIMEOUT): void
    {
        $storage = new LazyRedisStorage(static fn (): object => RedisConnectionFactory::create($dsn, $timeout, $timeout));
        $failure = null;
        $start = microtime(true);

        try {
            $storage->run([Operation::add('key', 1, 60)]);
        } catch (\Throwable $failure) {
        }

        $this->assertLessThan(self::MAX_SECONDS, microtime(true) - $start);
        $this->assertInstanceOf(\Throwable::class, $failure);
    }
}
