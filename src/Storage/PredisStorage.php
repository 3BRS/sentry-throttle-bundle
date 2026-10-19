<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Storage;

use Predis\ClientInterface;
use Predis\Connection\Cluster\ClusterInterface;
use Predis\Pipeline\Pipeline;
use Predis\Response\ErrorInterface;

/**
 * @internal
 */
final class PredisStorage implements StorageInterface
{
    public function __construct(
        private readonly ClientInterface $client,
    ) {
        if ($client->getConnection() instanceof ClusterInterface) {
            throw new StorageException('Redis Cluster is not supported.');
        }
    }

    public function increment(string $key, int $ttlSeconds): int
    {
        $replies = $this->transaction(static function (Pipeline $pipeline) use ($key, $ttlSeconds): void {
            $pipeline->incr($key);
            $pipeline->expire($key, $ttlSeconds);
        });

        return $this->integer($replies[0] ?? null, 'INCR');
    }

    public function pull(string $key): int
    {
        // INCRBY 0 returns the counter as an integer reply.
        $replies = $this->transaction(static function (Pipeline $pipeline) use ($key): void {
            $pipeline->incrby($key, 0);
            $pipeline->del($key);
        });

        return $this->integer($replies[0] ?? null, 'INCRBY');
    }

    /**
     * MULTI and EXEC inside a plain pipeline take one round trip; Predis transactions wait for every QUEUED reply.
     *
     * @param \Closure(Pipeline): void $commands
     *
     * @return array<mixed>
     */
    private function transaction(\Closure $commands): array
    {
        $pipeline = new Pipeline($this->client);
        $pipeline->multi();
        $commands($pipeline);
        $pipeline->exec();
        $replies = $pipeline->execute();
        $exec = end($replies);

        if (! \is_array($exec)) {
            throw new StorageException('Redis transaction failed: ' . ($exec instanceof ErrorInterface ? $exec->getMessage() : 'no reply'));
        }

        return $exec;
    }

    private function integer(mixed $reply, string $command): int
    {
        if (! \is_int($reply)) {
            throw new StorageException(\sprintf('Redis %s failed: %s', $command, $reply instanceof ErrorInterface ? $reply->getMessage() : 'unexpected reply'));
        }

        return $reply;
    }
}
