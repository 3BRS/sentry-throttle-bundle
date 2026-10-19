<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Storage;

/**
 * @internal
 */
final class PhpRedisStorage implements StorageInterface
{
    public function __construct(
        private readonly \Redis $redis,
    ) {
    }

    public function increment(string $key, int $ttlSeconds): int
    {
        $replies = $this->transaction(static function (\Redis $redis) use ($key, $ttlSeconds): void {
            $redis->incr($key);
            $redis->expire($key, $ttlSeconds);
        });

        return $this->integer($replies[0] ?? null, 'INCR');
    }

    public function pull(string $key): int
    {
        // INCRBY 0 returns the counter as an integer reply, which client serializer options do not touch.
        $replies = $this->transaction(static function (\Redis $redis) use ($key): void {
            $redis->incrBy($key, 0);
            $redis->del($key);
        });

        return $this->integer($replies[0] ?? null, 'INCRBY');
    }

    /**
     * MULTI inside a pipeline sends MULTI, the commands and EXEC in one round trip; multi() alone waits for every QUEUED reply.
     *
     * @param \Closure(\Redis): void $commands
     *
     * @return array<mixed>
     */
    private function transaction(\Closure $commands): array
    {
        $this->redis->pipeline();
        $this->redis->multi();
        $commands($this->redis);
        $this->redis->exec();
        $replies = $this->redis->exec();

        if (! \is_array($replies) || ! \is_array($replies[0] ?? null)) {
            throw new StorageException('Redis transaction failed: ' . ($this->redis->getLastError() ?? 'no reply'));
        }

        return $replies[0];
    }

    private function integer(mixed $reply, string $command): int
    {
        if (! \is_int($reply)) {
            throw new StorageException(\sprintf('Redis %s failed: %s', $command, $this->redis->getLastError() ?? 'unexpected reply'));
        }

        return $reply;
    }
}
