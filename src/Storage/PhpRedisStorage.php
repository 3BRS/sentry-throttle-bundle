<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Storage;

use Symfony\Component\Cache\Traits\RedisProxy;

/**
 * @internal
 */
final class PhpRedisStorage implements StorageInterface
{
    /**
     * @param RedisProxy|\Redis $redis RedisProxy is the lazy connection of Symfony 5.4 to 6.1, which forwards every call to \Redis
     */
    public function __construct(
        private readonly RedisProxy|\Redis $redis,
    ) {
    }

    public function run(array $operations): array
    {
        // A transaction or pipeline that the application has open on a shared client must stay untouched.
        if ($this->redis->getMode() !== \Redis::ATOMIC) {
            throw new StorageBusyException('The Redis client has a transaction or pipeline open.');
        }

        try {
            return $this->transaction($operations);
        } catch (\Throwable $e) {
            // After a rejected command phpredis may leave unread replies on the connection, which later commands
            // would read; it reconnects with the next command.
            $this->redis->close();

            throw $e;
        }
    }

    /**
     * MULTI inside a pipeline sends MULTI, the commands and EXEC in one round trip; multi() alone waits for every QUEUED reply.
     *
     * @param non-empty-list<Operation> $operations
     *
     * @return list<int>
     */
    private function transaction(array $operations): array
    {
        $this->redis->pipeline();
        $this->redis->multi();

        foreach ($operations as $operation) {
            // INCRBY returns the counter as an integer reply, which client serializer options do not touch.
            $this->redis->incrBy($operation->key, $operation->amount);

            if ($operation->take) {
                $this->redis->del($operation->key);
            } else {
                $this->redis->expire($operation->key, $operation->ttlSeconds);
            }
        }

        $this->redis->exec();
        $pipelineReplies = $this->redis->exec();

        if (! \is_array($pipelineReplies) || ! \is_array($pipelineReplies[0] ?? null)) {
            throw new StorageException('Redis transaction failed: ' . ($this->redis->getLastError() ?? 'no reply'));
        }

        $results = [];

        foreach ($operations as $index => $operation) {
            $results[] = $this->integer($pipelineReplies[0][2 * $index] ?? null, $operation->key);
        }

        return $results;
    }

    private function integer(mixed $reply, string $key): int
    {
        if (! \is_int($reply)) {
            throw new StorageException(\sprintf('Redis command on "%s" failed: %s', $key, $this->redis->getLastError() ?? 'unexpected reply'));
        }

        return $reply;
    }
}
