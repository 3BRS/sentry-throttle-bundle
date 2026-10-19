<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Throttle;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ThreeBRS\SentryThrottleBundle\Storage\StorageInterface;

/**
 * @internal
 */
final class Throttle implements ThrottleInterface
{
    private const KEY_PREFIX = 'sentry_throttle:';

    private const DROPPED_TTL_SECONDS = 86400;

    /**
     * Start of the window in which the storage failed; the storage is not used again until the window ends.
     */
    private ?int $failedWindow = null;

    private ?int $fallbackWindow = null;

    private int $fallbackCount = 0;

    private bool $deciding = false;

    /**
     * @param (\Closure(): int)|null $clock current Unix time
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $prefix,
        private readonly int $windowSeconds,
        private readonly int $perFingerprint,
        private readonly int $maxPerWindow,
        private readonly int $fallbackMaxPerWindow,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function decide(string $fingerprint): Decision
    {
        // An event raised while deciding (e.g. by the fail-open log) must not touch the storage again.
        if ($this->deciding) {
            return Decision::send();
        }

        $this->deciding = true;

        try {
            $window = $this->currentWindow();

            if ($this->failedWindow === $window) {
                return $this->fallback($window);
            }

            try {
                return $this->decideWithStorage($fingerprint, $window);
            } catch (\Throwable $e) {
                $this->failedWindow = $window;
                $decision = $this->fallback($window);
                $this->logger->warning('Sentry throttle cannot use Redis; this process sends at most {limit} events until the current {window}s window ends.', [
                    'limit' => $this->fallbackMaxPerWindow,
                    'window' => $this->windowSeconds,
                    'error' => $e->getMessage(),
                    'error_class' => $e::class,
                ]);

                return $decision;
            }
        } catch (\Throwable) {
            return Decision::send();
        } finally {
            $this->deciding = false;
        }
    }

    private function decideWithStorage(string $fingerprint, int $window): Decision
    {
        $keyPrefix = self::KEY_PREFIX . $this->prefix . ':';
        $droppedKey = $keyPrefix . 'dropped:' . $fingerprint;
        // Each increment renews the TTL, so a key outlives its window by at least one window.
        $windowTtl = 2 * $this->windowSeconds;

        if ($this->storage->increment($keyPrefix . 'fp:' . $window . ':' . $fingerprint, $windowTtl) > $this->perFingerprint) {
            $this->storage->increment($droppedKey, self::DROPPED_TTL_SECONDS);

            return Decision::drop();
        }

        if ($this->storage->increment($keyPrefix . 'all:' . $window, $windowTtl) > $this->maxPerWindow) {
            $this->storage->increment($droppedKey, self::DROPPED_TTL_SECONDS);

            return Decision::drop();
        }

        return Decision::send($this->storage->pull($droppedKey));
    }

    private function fallback(int $window): Decision
    {
        if ($this->fallbackWindow !== $window) {
            $this->fallbackWindow = $window;
            $this->fallbackCount = 0;
        }

        return ++$this->fallbackCount <= $this->fallbackMaxPerWindow ? Decision::send() : Decision::drop();
    }

    private function currentWindow(): int
    {
        $now = $this->clock instanceof \Closure ? ($this->clock)() : time();

        return $now - $now % $this->windowSeconds;
    }
}
