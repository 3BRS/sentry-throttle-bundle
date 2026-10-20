<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Integration\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Connection\NodeConnectionInterface;
use Predis\Transaction\MultiExec;
use ThreeBRS\SentryThrottleBundle\Storage\LazyRedisStorage;
use ThreeBRS\SentryThrottleBundle\Storage\Operation;
use ThreeBRS\SentryThrottleBundle\Storage\PhpRedisStorage;
use ThreeBRS\SentryThrottleBundle\Storage\RedisConnectionFactory;
use ThreeBRS\SentryThrottleBundle\Storage\StorageBusyException;
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
    public function testAddReturnsNewValueAndRenewsTtl(\Closure $client): void
    {
        $storage = $this->storage($client);

        $this->assertSame([1], $storage->run([Operation::add($this->key, 1, 60)]));
        $this->assertSame([3], $storage->run([Operation::add($this->key, 2, 60)]));
        $this->assertSame([2], $storage->run([Operation::add($this->key, -1, 30)]));

        $ttl = $this->redis()->ttl($this->key);
        $this->assertIsInt($ttl);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(30, $ttl);
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testTakeReturnsCounterAndDeletesIt(\Closure $client): void
    {
        $storage = $this->storage($client);
        $storage->run([Operation::add($this->key, 3, 60)]);

        $this->assertSame([3], $storage->run([Operation::take($this->key)]));
        $this->assertSame(0, $this->redis()->exists($this->key));
        $this->assertSame([0], $storage->run([Operation::take($this->key)]));
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testTakeOfMissingCounterDoesNotCreateIt(\Closure $client): void
    {
        $this->assertSame([0], $this->storage($client)->run([Operation::take($this->key)]));
        $this->assertSame(0, $this->redis()->exists($this->key));
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testReturnsResultOfEachOperationInOrder(\Closure $client): void
    {
        $otherKey = $this->key . ':other';

        $this->assertSame([1, 5, 1, 2], $this->storage($client)->run([
            Operation::add($this->key, 1, 60),
            Operation::add($otherKey, 5, 60),
            Operation::take($this->key),
            Operation::add($this->key, 2, 60),
        ]));
        $this->redis()->del($otherKey);
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testAddFailsOnValueThatIsNotCounter(\Closure $client): void
    {
        $this->redis()->set($this->key, 'text', [
            'EX' => 60,
        ]);

        $this->expectException(StorageException::class);

        $this->storage($client)->run([Operation::add($this->key, 1, 60)]);
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testTakeFailsOnValueThatIsNotCounter(\Closure $client): void
    {
        $this->redis()->set($this->key, 'text', [
            'EX' => 60,
        ]);

        $this->expectException(StorageException::class);

        $this->storage($client)->run([Operation::take($this->key)]);
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
        $policy = $redis->config('GET', 'maxmemory-policy');
        // With any other policy the full Redis would evict every key of the server instead of rejecting writes.
        $redis->config('SET', 'maxmemory-policy', 'noeviction');
        $redis->config('SET', 'maxmemory', '1');
        $failures = 0;

        try {
            foreach ([Operation::add($this->key, 1, 60), Operation::take($this->key)] as $operation) {
                try {
                    $storage->run([$operation]);
                } catch (\Throwable) {
                    ++$failures;
                }
            }
        } finally {
            $redis->config('SET', 'maxmemory', \is_array($maxMemory) ? (string) ($maxMemory['maxmemory'] ?? '0') : '0');
            $redis->config('SET', 'maxmemory-policy', \is_array($policy) ? (string) ($policy['maxmemory-policy'] ?? 'noeviction') : 'noeviction');
        }

        $this->assertSame(2, $failures);
        // No reply left on the connection by the failures is read once Redis accepts writes again.
        $this->assertSame([1], $storage->run([Operation::add($this->key, 1, 60)]));
    }

    /**
     * @param \Closure(\Redis): void $open
     */
    #[DataProvider('openModes')]
    public function testLeavesOpenTransactionOrPipelineOfSharedPhpRedisClient(\Closure $open): void
    {
        $redis = $this->redis();
        $open($redis);
        $redis->set($this->key, 'application', [
            'EX' => 60,
        ]);
        $failure = null;

        try {
            (new PhpRedisStorage($redis))->run([Operation::add($this->key . ':counter', 1, 60)]);
        } catch (StorageBusyException $failure) {
        }

        $this->assertInstanceOf(StorageBusyException::class, $failure);
        $this->assertSame([true], $redis->exec());
        $this->assertSame('application', $this->redis()->get($this->key));
        $this->assertSame(0, $this->redis()->exists($this->key . ':counter'));
    }

    /**
     * @return iterable<string, array{\Closure(\Redis): void}>
     */
    public static function openModes(): iterable
    {
        yield 'transaction' => [static function (\Redis $redis): void {
            $redis->multi();
        }];
        yield 'pipeline' => [static function (\Redis $redis): void {
            $redis->pipeline();
        }];
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('predisClients')]
    public function testLeavesTransactionOfSharedPredisClient(\Closure $client): void
    {
        $application = $client(self::dsn());
        $this->assertInstanceOf(Client::class, $application);
        $storage = new LazyRedisStorage(static fn (): object => $application, sharedClient: true);

        $transaction = $application->transaction();
        $this->assertInstanceOf(MultiExec::class, $transaction);
        $transaction->set($this->key, 'application');
        $this->assertSame([1], $storage->run([Operation::add($this->key . ':counter', 1, 60)]));
        $transaction->expire($this->key, 60);

        $this->assertCount(2, $transaction->execute());
        $this->assertSame('application', $this->redis()->get($this->key));
    }

    /**
     * @return iterable<string, array{\Closure(string): object}>
     */
    public static function predisClients(): iterable
    {
        foreach (self::clients() as $name => $client) {
            if (str_starts_with($name, 'predis')) {
                yield $name => $client;
            }
        }

        // Symfony puts "persistent" also into the "parameters" option of the client.
        yield 'persistent predis from DSN' => [
            static fn (string $dsn): object => RedisConnectionFactory::create($dsn . '?persistent=1&class=' . rawurlencode(Client::class), 1.0, 1.0),
        ];
    }

    public function testCountsOnPhpRedisClientWithSerializer(): void
    {
        $redis = $this->redis();
        $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $storage = new PhpRedisStorage($redis);

        $storage->run([Operation::add($this->key, 1, 60)]);
        $storage->run([Operation::add($this->key, 1, 60)]);

        $this->assertSame([2], $storage->run([Operation::take($this->key)]));
    }

    /**
     * @param \Closure(string): object $client
     */
    #[DataProvider('clients')]
    public function testTakesOneRoundTripPerRun(\Closure $client): void
    {
        $proxy = new RoundTripProxy(self::dsn());

        try {
            $storage = new LazyRedisStorage(static fn (): object => $client($proxy->dsn));
            // Connecting may take extra round trips (SELECT, CLIENT SETINFO).
            $storage->run([Operation::add($this->key, 1, 60)]);
            $before = $proxy->roundTrips();

            $storage->run([
                Operation::add($this->key, 1, 60),
                Operation::add($this->key . ':other', 1, 60),
                Operation::take($this->key . ':other'),
            ]);
            $this->assertSame(1, $proxy->roundTrips() - $before);

            $storage->run([Operation::take($this->key)]);
            $this->assertSame(2, $proxy->roundTrips() - $before);
        } finally {
            $proxy->stop();
        }
    }

    /**
     * Predis 1 and 2 write each command of a pipeline separately; with Nagle's algorithm the commands after the first
     * one wait until it is acknowledged, which costs another round trip. Predis 1 applies the parameter only with the
     * sockets extension.
     */
    public function testPredisConnectionFromDsnDisablesNagleAlgorithm(): void
    {
        $client = RedisConnectionFactory::create(self::dsn() . '?class=' . rawurlencode(Client::class), 1.0, 1.0);
        $this->assertInstanceOf(Client::class, $client);
        $connection = $client->getConnection();
        $this->assertInstanceOf(NodeConnectionInterface::class, $connection);

        $this->assertTrue($connection->getParameters()->toArray()['tcp_nodelay'] ?? null);
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

        $storage->run([Operation::add($this->key, 1, 60)]);
        $storage->run([Operation::take($this->key)]);

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
        // On Symfony 5.4 to 6.1 a lazy connection is a RedisProxy, which does not extend \Redis.
        yield 'phpredis lazy' => [
            static fn (string $dsn): object => RedisConnectionFactory::create($dsn . '?lazy=1', 1.0, 1.0),
        ];
        // DSN query parameters override the factory options, so Predis is used although ext-redis is loaded.
        yield 'predis from DSN' => [
            static fn (string $dsn): object => RedisConnectionFactory::create($dsn . '?class=' . rawurlencode(Client::class), 1.0, 1.0),
        ];
        // Unlike the DSN connection, a client created directly throws on error replies; it has tcp_nodelay, as the
        // README recommends for an existing Predis client.
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
