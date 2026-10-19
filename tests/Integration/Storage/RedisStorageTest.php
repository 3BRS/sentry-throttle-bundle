<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Integration\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use ThreeBRS\SentryThrottleBundle\Storage\LazyRedisStorage;
use ThreeBRS\SentryThrottleBundle\Storage\PhpRedisStorage;
use ThreeBRS\SentryThrottleBundle\Storage\RedisConnectionFactory;
use ThreeBRS\SentryThrottleBundle\Storage\StorageException;
use ThreeBRS\SentryThrottleBundle\Storage\StorageInterface;

final class RedisStorageTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = 'sentry_throttle_test:' . bin2hex(random_bytes(8));
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testIncrementCountsAndSetsTtl(\Closure $client): void
    {
        $storage = $this->storage($client);

        $this->assertSame(1, $storage->increment($this->key, 60));
        $this->assertSame(2, $storage->increment($this->key, 60));

        $ttl = $this->redis()->ttl($this->key);
        $this->assertIsInt($ttl);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testPullReturnsCounterAndDeletesIt(\Closure $client): void
    {
        $storage = $this->storage($client);
        $storage->increment($this->key, 60);
        $storage->increment($this->key, 60);
        $storage->increment($this->key, 60);

        $this->assertSame(3, $storage->pull($this->key));
        $this->assertSame(0, $this->redis()->exists($this->key));
        $this->assertSame(0, $storage->pull($this->key));
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testPullOfMissingCounterDoesNotCreateIt(\Closure $client): void
    {
        $this->assertSame(0, $this->storage($client)->pull($this->key));
        $this->assertSame(0, $this->redis()->exists($this->key));
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testIncrementFailsOnValueThatIsNotCounter(\Closure $client): void
    {
        $this->redis()->set($this->key, 'text', [
            'EX' => 60,
        ]);

        $this->expectException(StorageException::class);

        $this->storage($client)->increment($this->key, 60);
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testPullFailsOnValueThatIsNotCounter(\Closure $client): void
    {
        $this->redis()->set($this->key, 'text', [
            'EX' => 60,
        ]);

        $this->expectException(StorageException::class);

        $this->storage($client)->pull($this->key);
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testFailsWhenRedisIsFull(\Closure $client): void
    {
        $storage = $this->storage($client);
        $redis = $this->redis();
        $maxMemory = $redis->config('GET', 'maxmemory');
        $redis->config('SET', 'maxmemory', '1');
        $failures = 0;

        try {
            foreach ([
                fn (): int => $storage->increment($this->key, 60),
                fn (): int => $storage->pull($this->key),
            ] as $call) {
                try {
                    $call();
                } catch (\Throwable) {
                    ++$failures;
                }
            }
        } finally {
            $redis->config('SET', 'maxmemory', \is_array($maxMemory) ? (string) ($maxMemory['maxmemory'] ?? '0') : '0');
        }

        $this->assertSame(2, $failures);
    }

    public function testCountsOnPhpRedisClientWithSerializer(): void
    {
        $redis = $this->redis();
        $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $storage = new PhpRedisStorage($redis);

        $storage->increment($this->key, 60);
        $storage->increment($this->key, 60);

        $this->assertSame(2, $storage->pull($this->key));
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testTakesOneRoundTripPerCall(\Closure $client): void
    {
        $proxy = new RoundTripProxy(self::dsn());

        try {
            $storage = new LazyRedisStorage(static fn (): object => $client($proxy->dsn));
            // Connecting may take extra round trips (SELECT, CLIENT SETINFO).
            $storage->increment($this->key, 60);
            $before = $proxy->roundTrips();

            $storage->increment($this->key, 60);
            $this->assertSame(1, $proxy->roundTrips() - $before);

            $storage->pull($this->key);
            $this->assertSame(2, $proxy->roundTrips() - $before);
        } finally {
            $proxy->stop();
        }
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testGetsClientOnce(\Closure $client): void
    {
        $calls = 0;
        $storage = new LazyRedisStorage(static function () use ($client, &$calls): object {
            ++$calls;

            return $client(self::dsn());
        });

        $storage->increment($this->key, 60);
        $storage->pull($this->key);

        $this->assertSame(1, $calls);
    }

    /**
     * @return iterable<string, array{\Closure(string): object}>
     */
    public static function clients(): iterable
    {
        yield 'phpredis' => [
            static fn (string $dsn): object => RedisConnectionFactory::create($dsn, 1.0, 1.0),
        ];
        // DSN query parameters override the factory options, so Predis is used although ext-redis is loaded.
        yield 'predis from DSN' => [
            static fn (string $dsn): object => RedisConnectionFactory::create($dsn . '?class=' . rawurlencode(Client::class), 1.0, 1.0),
        ];
        // Unlike the DSN connection, a client created directly throws on error replies. Predis 2 writes each command
        // of a pipeline separately, so without tcp_nodelay Nagle's algorithm holds the rest until the first is acknowledged.
        yield 'predis client' => [
            static fn (string $dsn): object => new Client($dsn . '?tcp_nodelay=1'),
        ];
    }

    /**
     * @param \Closure(string): object $client
     */
    private function storage(\Closure $client): StorageInterface
    {
        return new LazyRedisStorage(static fn (): object => $client(self::dsn()));
    }

    private function redis(): \Redis
    {
        $redis = RedisConnectionFactory::create(self::dsn(), 1.0, 1.0);
        $this->assertInstanceOf(\Redis::class, $redis);

        return $redis;
    }

    private static function dsn(): string
    {
        $dsn = getenv('SENTRY_THROTTLE_TEST_REDIS_DSN');

        return \is_string($dsn) && $dsn !== '' ? $dsn : 'redis://localhost:6379';
    }
}
