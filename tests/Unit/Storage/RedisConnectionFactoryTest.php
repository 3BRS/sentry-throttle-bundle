<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use ThreeBRS\SentryThrottleBundle\Storage\RedisConnectionFactory;
use ThreeBRS\SentryThrottleBundle\Storage\StorageException;

final class RedisConnectionFactoryTest extends TestCase
{
    #[DataProvider('timeoutsOutOfRange')]
    public function testRejectsTimeoutsOutOfRange(string $dsn, float $timeout, float $readTimeout): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('"timeout" and "read_timeout" must be greater than 0');

        RedisConnectionFactory::create($dsn, $timeout, $readTimeout);
    }

    /**
     * @return iterable<string, array{string, float, float}>
     */
    public static function timeoutsOutOfRange(): iterable
    {
        yield 'zero timeout' => ['redis://localhost', 0.0, 0.2];
        yield 'zero read timeout' => ['redis://localhost', 0.2, 0.0];
        yield 'negative timeout' => ['redis://localhost', -1.0, 0.2];
        // Query parameters of the DSN override the options.
        yield 'zero timeout in DSN' => ['redis://localhost?timeout=0', 0.2, 0.2];
        yield 'zero read timeout in DSN' => ['redis://localhost?read_timeout=0', 0.2, 0.2];
        yield 'zero timeout in DSN of unix socket' => ['redis:///var/run/redis.sock?timeout=0', 0.2, 0.2];
        yield 'zero timeout in DSN with "/" in password' => ['redis://:ab/cd@localhost:6379?timeout=0', 0.2, 0.2];
        yield 'zero timeout in DSN with "?" in password' => ['redis://:ab?cd@localhost:6379?timeout=0', 0.2, 0.2];
    }

    public function testUsesPredisWithoutRedisExtension(): void
    {
        // A Predis client connects with its first command, so no Redis is needed here.
        $process = proc_open([\PHP_BINARY, '-n', __DIR__ . '/create-connection.php', 'redis://localhost:6379'], [
            1 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        $this->assertSame(Client::class, $output);
    }

    public function testChecksTimeoutsOfDsnInsteadOfOptions(): void
    {
        $this->expectNotToPerformAssertions();

        // A lazy connection is not opened here.
        RedisConnectionFactory::create('redis://localhost?lazy=1&timeout=1&read_timeout=1', 0.0, 0.0);
    }
}
