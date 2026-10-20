<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Throttle;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ThreeBRS\SentryThrottleBundle\Storage\Operation;
use ThreeBRS\SentryThrottleBundle\Storage\StorageBusyException;
use ThreeBRS\SentryThrottleBundle\Storage\StorageInterface;

/**
 * @internal
 */
final class Throttle implements ThrottleInterface
{
    public const LOG_CHANNEL = 'sentry_throttle';

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
     * Options out of range, e.g. from environment variables, which are not validated when the container is compiled.
     *
     * @var list<string>
     */
    private readonly array $invalidOptions;

    private bool $invalidOptionsLogged = false;

    private readonly LoggerInterface $logger;

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
        ?LoggerInterface $logger = null,
        private readonly ?\Closure $clock = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->invalidOptions = array_keys(array_filter([
            'prefix' => $prefix === '',
            'window' => $windowSeconds < 1,
            'per_fingerprint' => $perFingerprint < 1,
            'max_per_window' => $maxPerWindow < 1,
            'fallback_max_per_window' => $fallbackMaxPerWindow < 1,
        ]));
    }

    public function decide(string $fingerprint): Decision
    {
        // An event raised while deciding (e.g. by the fail-open log) must not touch the storage again.
        if ($this->deciding) {
            return Decision::send();
        }

        $this->deciding = true;

        try {
            if ($this->invalidOptions !== []) {
                return $this->sendWithInvalidOptions();
            }

            $window = $this->currentWindow();

            if ($this->failedWindow === $window) {
                return $this->fallback($window);
            }

            try {
                return $this->decideWithStorage($fingerprint, $window);
            } catch (StorageBusyException) {
                // Not a failure: only this event is decided without the storage.
                return $this->fallback($window);
            } catch (\Throwable $e) {
                $this->failedWindow = $window;
                $decision = $this->fallback($window);
                $this->logger->warning('Sentry throttle cannot use Redis; this process sends at most {limit} events until the current {window}s window ends.', [
                    'prefix' => $this->prefix,
                    'limit' => $this->fallbackMaxPerWindow,
                    'window' => $this->windowSeconds,
                    'error' => $this->withoutCredentials($e->getMessage()),
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
        $globalKey = $keyPrefix . 'all:' . $window;
        $droppedKey = $keyPrefix . 'dropped:' . $fingerprint;
        // Every operation renews the TTL, so a key outlives its window by at least one window.
        $windowTtl = 2 * $this->windowSeconds;

        $fingerprintKey = $keyPrefix . 'fp:' . $window . ':' . $fingerprint;

        [$fingerprintCount, $globalCount, $dropped] = $this->storage->run([
            Operation::add($fingerprintKey, 1, $windowTtl),
            Operation::add($globalKey, 1, $windowTtl),
            Operation::take($droppedKey),
        ]);

        if ($fingerprintCount <= $this->perFingerprint && $globalCount <= $this->maxPerWindow) {
            return Decision::send($dropped);
        }

        // A dropped event puts back the dropped count it took, plus one for itself, and takes itself off both
        // window counters, so that they count only sent events.
        $this->storage->run([
            Operation::add($droppedKey, $dropped + 1, self::DROPPED_TTL_SECONDS),
            Operation::add($fingerprintKey, -1, $windowTtl),
            Operation::add($globalKey, -1, $windowTtl),
        ]);

        return Decision::drop();
    }

    private function sendWithInvalidOptions(): Decision
    {
        if (! $this->invalidOptionsLogged) {
            $this->invalidOptionsLogged = true;
            $this->logger->warning('Sentry throttle sends every event because these options are out of range: {options}.', [
                'prefix' => $this->prefix,
                'options' => implode(', ', $this->invalidOptions),
            ]);
        }

        return Decision::send();
    }

    private function fallback(int $window): Decision
    {
        if ($this->fallbackWindow !== $window) {
            $this->fallbackWindow = $window;
            $this->fallbackCount = 0;
        }

        return ++$this->fallbackCount <= $this->fallbackMaxPerWindow ? Decision::send() : Decision::drop();
    }

    /**
     * Older symfony/cache versions put the whole DSN, password included, into their exception messages. The password
     * is the user info of the DSN or, for Redis Sentinel, the "auth" query parameter.
     */
    private function withoutCredentials(string $message): string
    {
        return preg_replace(['#(rediss?:(?://)?)[^@]*+@#', '#([?&]auth=)[^&"\s]*+#'], ['$1***@', '$1***'], $message) ?? '';
    }

    private function currentWindow(): int
    {
        $now = $this->clock instanceof \Closure ? ($this->clock)() : time();

        return $now - $now % $this->windowSeconds;
    }
}
