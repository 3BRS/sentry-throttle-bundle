<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Storage;

/**
 * Implementations throw on any connection error or unexpected reply.
 *
 * @internal
 */
interface StorageInterface
{
    /**
     * Increments the counter and sets its TTL in one atomic step.
     */
    public function increment(string $key, int $ttlSeconds): int;

    /**
     * Returns the counter and deletes it in one atomic step; a missing counter is 0.
     */
    public function pull(string $key): int;
}
