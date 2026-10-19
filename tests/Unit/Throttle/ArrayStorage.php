<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Throttle;

use ThreeBRS\SentryThrottleBundle\Storage\StorageInterface;

/**
 * Counters in memory; TTLs are ignored because window keys change with the window.
 */
final class ArrayStorage implements StorageInterface
{
    /**
     * @var array<string, int>
     */
    public array $counters = [];

    public int $calls = 0;

    public ?\Throwable $failure = null;

    /**
     * @var (\Closure(): void)|null
     */
    public ?\Closure $onCall = null;

    public function increment(string $key, int $ttlSeconds): int
    {
        $this->call();

        return $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    public function pull(string $key): int
    {
        $this->call();
        $value = $this->counters[$key] ?? 0;
        unset($this->counters[$key]);

        return $value;
    }

    private function call(): void
    {
        ++$this->calls;

        if ($this->onCall instanceof \Closure) {
            ($this->onCall)();
        }

        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }
    }
}
