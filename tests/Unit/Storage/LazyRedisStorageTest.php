<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use ThreeBRS\SentryThrottleBundle\Storage\LazyRedisStorage;
use ThreeBRS\SentryThrottleBundle\Storage\StorageException;

final class LazyRedisStorageTest extends TestCase
{
    public function testGetsClientOnFirstUse(): void
    {
        $calls = 0;
        $storage = new LazyRedisStorage(static function () use (&$calls): object {
            ++$calls;

            return new \stdClass();
        });

        $this->assertSame(0, $calls);

        try {
            $storage->increment('key', 60);
        } catch (StorageException) {
        }

        $this->assertSame(1, $calls);
    }

    public function testRejectsUnsupportedClient(): void
    {
        $storage = new LazyRedisStorage(static fn (): object => new \stdClass());

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Unsupported Redis client "stdClass".');

        $storage->increment('key', 60);
    }

    public function testRepeatsFailedAttemptToGetClient(): void
    {
        $calls = 0;
        $storage = new LazyRedisStorage(static function () use (&$calls): object {
            if (++$calls === 1) {
                throw new \RuntimeException('Connection refused');
            }

            return new \stdClass();
        });
        $failures = [];

        foreach ([
            static fn (): int => $storage->increment('key', 60),
            static fn (): int => $storage->pull('key'),
        ] as $call) {
            try {
                $call();
            } catch (\Throwable $e) {
                $failures[] = $e::class;
            }
        }

        $this->assertSame([\RuntimeException::class, StorageException::class], $failures);
        $this->assertSame(2, $calls);
    }
}
