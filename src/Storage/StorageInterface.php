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
     * Runs the operations as one atomic transaction in one round trip.
     *
     * @param non-empty-list<Operation> $operations
     *
     * @return list<int> the result of each operation, in the same order
     */
    public function run(array $operations): array;
}
