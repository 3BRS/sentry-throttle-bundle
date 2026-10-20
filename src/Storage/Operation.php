<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Storage;

/**
 * A counter operation of a storage transaction; its result is an integer.
 *
 * @internal
 */
final class Operation
{
    private function __construct(
        public readonly string $key,
        public readonly int $amount,
        public readonly int $ttlSeconds,
        public readonly bool $take,
    ) {
    }

    /**
     * Adds the amount (a negative one subtracts) and renews the TTL; the result is the new value.
     */
    public static function add(string $key, int $amount, int $ttlSeconds): self
    {
        return new self($key, $amount, $ttlSeconds, false);
    }

    /**
     * Deletes the counter; the result is the value it had, 0 for a missing counter.
     */
    public static function take(string $key): self
    {
        return new self($key, 0, 0, true);
    }
}
