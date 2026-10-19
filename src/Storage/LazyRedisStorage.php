<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Storage;

use Predis\ClientInterface;

/**
 * Gets the Redis client on first use; a failed attempt is repeated on the next call.
 *
 * @internal
 */
final class LazyRedisStorage implements StorageInterface
{
    private ?StorageInterface $storage = null;

    /**
     * @param \Closure(): object $client
     */
    public function __construct(
        private readonly \Closure $client,
    ) {
    }

    public function increment(string $key, int $ttlSeconds): int
    {
        return $this->storage()->increment($key, $ttlSeconds);
    }

    public function pull(string $key): int
    {
        return $this->storage()->pull($key);
    }

    private function storage(): StorageInterface
    {
        if ($this->storage instanceof StorageInterface) {
            return $this->storage;
        }

        $client = ($this->client)();

        return $this->storage = match (true) {
            $client instanceof \Redis => new PhpRedisStorage($client),
            $client instanceof ClientInterface => new PredisStorage($client),
            default => throw new StorageException(\sprintf('Unsupported Redis client "%s".', get_debug_type($client))),
        };
    }
}
