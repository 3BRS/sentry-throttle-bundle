<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Storage;

use Predis\ClientInterface;
use Symfony\Component\Cache\Traits\RedisProxy;

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
     * @param bool $sharedClient whether the client is a service the application uses too
     */
    public function __construct(
        private readonly \Closure $client,
        private readonly bool $sharedClient = false,
    ) {
    }

    public function run(array $operations): array
    {
        return $this->storage()->run($operations);
    }

    private function storage(): StorageInterface
    {
        if ($this->storage instanceof StorageInterface) {
            return $this->storage;
        }

        $client = ($this->client)();

        return $this->storage = match (true) {
            $client instanceof RedisProxy, $client instanceof \Redis => new PhpRedisStorage($client),
            $client instanceof ClientInterface => $this->sharedClient ? PredisStorage::withOwnConnection($client) : new PredisStorage($client),
            default => throw new StorageException(\sprintf('Unsupported Redis client "%s".', get_debug_type($client))),
        };
    }
}
