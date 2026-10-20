<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Throttle;

use ThreeBRS\SentryThrottleBundle\Storage\StorageInterface;

/**
 * Counters in memory; TTLs are ignored because window keys change with the window. $calls counts transactions,
 * i.e. round trips.
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

    public function run(array $operations): array
    {
        $this->call();
        $results = [];

        foreach ($operations as $operation) {
            if ($operation->take) {
                $results[] = $this->counters[$operation->key] ?? 0;
                unset($this->counters[$operation->key]);

                continue;
            }

            $results[] = $this->counters[$operation->key] = ($this->counters[$operation->key] ?? 0) + $operation->amount;
        }

        return $results;
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
