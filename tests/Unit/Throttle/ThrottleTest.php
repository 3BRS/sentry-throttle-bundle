<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Throttle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ThreeBRS\SentryThrottleBundle\Storage\StorageBusyException;
use ThreeBRS\SentryThrottleBundle\Throttle\Decision;
use ThreeBRS\SentryThrottleBundle\Throttle\Throttle;

final class ThrottleTest extends TestCase
{
    private const WINDOW_START = 1_800_000_000;

    private ArrayStorage $storage;

    private int $now = self::WINDOW_START;

    /**
     * @var list<array{string, array<mixed>}>
     */
    private array $logs = [];

    private ?\Throwable $logFailure = null;

    protected function setUp(): void
    {
        $this->storage = new ArrayStorage();
    }

    public function testDropsEventsOverPerFingerprintLimit(): void
    {
        $throttle = $this->throttle(perFingerprint: 50);

        $this->assertSame(array_fill(0, 50, true), $this->sends($throttle, 'a', 50));
        $this->assertFalse($throttle->decide('a')->send);
    }

    public function testPerFingerprintLimitStartsAgainInNextWindow(): void
    {
        $throttle = $this->throttle(perFingerprint: 1);
        $throttle->decide('a');

        $this->now = self::WINDOW_START + 59;
        $this->assertFalse($throttle->decide('a')->send);

        $this->now = self::WINDOW_START + 60;
        $this->assertTrue($throttle->decide('a')->send);
    }

    public function testGlobalCapCountsOnlyEventsPassingPerFingerprintLimit(): void
    {
        $throttle = $this->throttle(perFingerprint: 2, maxPerWindow: 5);

        $this->assertSame([true, true, false, false, false, false, false, false, false, false], $this->sends($throttle, 'a', 10));
        $this->assertTrue($throttle->decide('b')->send);
        $this->assertTrue($throttle->decide('c')->send);
        $this->assertTrue($throttle->decide('d')->send);
        $this->assertFalse($throttle->decide('e')->send);
    }

    public function testWindowCountersCountOnlySentEvents(): void
    {
        $throttle = $this->throttle(perFingerprint: 1, maxPerWindow: 2);

        $this->assertSame([true, false, true, false], [
            $throttle->decide('a')->send,
            $throttle->decide('a')->send,
            $throttle->decide('b')->send,
            $throttle->decide('c')->send,
        ]);
        $this->assertSame([
            'sentry_throttle:app:fp:1800000000:a' => 1,
            'sentry_throttle:app:all:1800000000' => 2,
            'sentry_throttle:app:fp:1800000000:b' => 1,
            'sentry_throttle:app:fp:1800000000:c' => 0,
        ], array_filter($this->storage->counters, static fn (string $key): bool => ! str_contains($key, ':dropped:'), \ARRAY_FILTER_USE_KEY));
    }

    public function testEventDroppedWhileAnotherIsBetweenItsRoundTripsGivesItsPlaceInWindowTotalBack(): void
    {
        [$throttle, $concurrent] = $this->decideWhileDroppedEventIsBetweenItsRoundTrips('b');

        $this->assertFalse($concurrent->send);
        $this->assertTrue($throttle->decide('c')->send);
    }

    public function testEventDroppedWhileAnotherIsBetweenItsRoundTripsGivesItsPlaceInFingerprintCountBack(): void
    {
        [$throttle, $concurrent] = $this->decideWhileDroppedEventIsBetweenItsRoundTrips('b');

        $this->assertFalse($concurrent->send);
        $this->assertTrue($throttle->decide('b')->send);
    }

    public function testDecidesEventWithoutStorageWhileStorageIsBusy(): void
    {
        $throttle = $this->throttle(perFingerprint: 1);
        $this->storage->failure = new StorageBusyException('The Redis client has a transaction or pipeline open.');

        $this->assertTrue($throttle->decide('a')->send);
        $this->assertSame([], $this->logs);

        $this->storage->failure = null;
        $this->assertTrue($throttle->decide('a')->send);
        $this->assertFalse($throttle->decide('a')->send);
        $this->assertSame(1 + 1 + 2, $this->storage->calls);
    }

    public function testEventsDecidedWhileStorageIsBusyCountTowardsFallbackLimit(): void
    {
        $throttle = $this->throttle(fallbackMaxPerWindow: 1);
        $this->storage->failure = new StorageBusyException('The Redis client has a transaction or pipeline open.');

        $this->assertSame([true, false], $this->sends($throttle, 'a', 2));
        $this->assertSame([], $this->logs);
    }

    public function testFirstEventSentAfterDropsCarriesDroppedCount(): void
    {
        $throttle = $this->throttle(perFingerprint: 1);

        $this->assertSame(0, $throttle->decide('a')->droppedSinceLastSent);
        $this->sends($throttle, 'a', 2);

        $this->now += 60;
        $this->assertSame([
            'throttled' => 'true',
            'throttle_occurrences' => '2',
        ], $throttle->decide('a')->tags());
        $throttle->decide('a');

        $this->now += 60;
        $this->assertSame(1, $throttle->decide('a')->droppedSinceLastSent);
        $this->now += 60;
        $this->assertSame(0, $throttle->decide('a')->droppedSinceLastSent);
    }

    public function testDroppedCountBelongsToItsFingerprint(): void
    {
        $throttle = $this->throttle(perFingerprint: 1);
        $this->sends($throttle, 'a', 2);

        $this->assertSame(0, $throttle->decide('b')->droppedSinceLastSent);
    }

    public function testEventsDroppedByGlobalCapAreCounted(): void
    {
        $throttle = $this->throttle(perFingerprint: 10, maxPerWindow: 1);
        $throttle->decide('a');

        $this->assertFalse($throttle->decide('b')->send);

        $this->now += 60;
        $this->assertSame(1, $throttle->decide('b')->droppedSinceLastSent);
    }

    public function testStorageCallsPerDecision(): void
    {
        $throttle = $this->throttle(perFingerprint: 1, maxPerWindow: 1);

        // Each storage call is one round trip, see RedisStorageTest.
        $throttle->decide('a');
        $this->assertSame(1, $this->storage->calls, 'sent');

        $throttle->decide('a');
        $this->assertSame(1 + 2, $this->storage->calls, 'dropped by the fingerprint limit');

        $throttle->decide('b');
        $this->assertSame(1 + 2 + 2, $this->storage->calls, 'dropped by the global cap');
    }

    public function testKeysContainPrefixWindowAndFingerprint(): void
    {
        $throttle = $this->throttle(perFingerprint: 1);
        $this->sends($throttle, 'a', 2);

        $this->assertSame([
            'sentry_throttle:app:fp:1800000000:a' => 1,
            'sentry_throttle:app:all:1800000000' => 1,
            'sentry_throttle:app:dropped:a' => 1,
        ], $this->storage->counters);
    }

    public function testSendsWhenStorageFails(): void
    {
        $this->storage->failure = new \RuntimeException('Connection refused');

        $decision = $this->throttle()->decide('a');

        $this->assertTrue($decision->send);
        $this->assertSame([], $decision->tags());
    }

    public function testLimitsEventsPerProcessWithoutUsingStorageAgainUntilWindowEnds(): void
    {
        $this->storage->failure = new \RuntimeException('Connection refused');
        $throttle = $this->throttle(fallbackMaxPerWindow: 3);

        $this->assertSame([true, true, true, false, false], [
            $throttle->decide('a')->send,
            $throttle->decide('b')->send,
            $throttle->decide('a')->send,
            $throttle->decide('c')->send,
            $throttle->decide('a')->send,
        ]);
        $this->assertSame(1, $this->storage->calls);
    }

    public function testUsesStorageAgainInNextWindow(): void
    {
        $this->storage->failure = new \RuntimeException('Connection refused');
        $throttle = $this->throttle(perFingerprint: 1, fallbackMaxPerWindow: 1);
        $this->sends($throttle, 'a', 2);

        $this->storage->failure = null;
        $this->now += 60;

        $this->assertSame([true, false], $this->sends($throttle, 'a', 2));
        $this->assertSame(1 + 1 + 2, $this->storage->calls);
    }

    public function testFallbackLimitStartsAgainInNextWindow(): void
    {
        $this->storage->failure = new \RuntimeException('Connection refused');
        $throttle = $this->throttle(fallbackMaxPerWindow: 1);
        $this->sends($throttle, 'a', 2);

        $this->now += 60;

        $this->assertSame([true, false], $this->sends($throttle, 'a', 2));
    }

    public function testLogsStorageFailureOncePerWindowWithoutException(): void
    {
        $this->storage->failure = new \RuntimeException('Connection refused');
        $throttle = $this->throttle(fallbackMaxPerWindow: 50);
        $this->sends($throttle, 'a', 5);
        $this->now += 60;
        $this->sends($throttle, 'a', 5);

        $this->assertCount(2, $this->logs);
        [$message, $context] = $this->logs[0];
        $this->assertStringContainsString('{limit}', $message);
        $this->assertSame('app', $context['prefix']);
        $this->assertSame(50, $context['limit']);
        $this->assertSame(60, $context['window']);
        $this->assertSame('Connection refused', $context['error']);
        $this->assertSame(\RuntimeException::class, $context['error_class']);
        $this->assertArrayNotHasKey('exception', $context);
    }

    #[DataProvider('errorsWithCredentials')]
    public function testLogsStorageFailureWithoutRedisPassword(string $error, string $logged): void
    {
        $this->storage->failure = new \InvalidArgumentException($error);

        $this->throttle()->decide('a');

        $this->assertSame($logged, $this->logs[0][1]['error']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function errorsWithCredentials(): iterable
    {
        yield 'user and password' => [
            'Redis connection "redis://user:secret@redis:6379/2" failed: Connection refused.',
            'Redis connection "redis://***@redis:6379/2" failed: Connection refused.',
        ];
        yield 'password with "/", "?" and ":"' => [
            'Redis connection "rediss://:se/c?r:et@redis:6379" failed: (timeout).',
            'Redis connection "rediss://***@redis:6379" failed: (timeout).',
        ];
        yield 'DSN without "//"' => [
            'Invalid Redis DSN: "redis:secret@/var/run/redis.sock".',
            'Invalid Redis DSN: "redis:***@/var/run/redis.sock".',
        ];
        yield 'Sentinel "auth" parameter' => [
            'Failed to retrieve master information from sentinel "mymaster" and dsn "redis:?host[sentinel:26379]&redis_sentinel=mymaster&auth=secret".',
            'Failed to retrieve master information from sentinel "mymaster" and dsn "redis:?host[sentinel:26379]&redis_sentinel=mymaster&auth=***".',
        ];
        yield 'no credentials' => [
            'Redis connection "redis://redis:6379" failed: Connection refused.',
            'Redis connection "redis://redis:6379" failed: Connection refused.',
        ];
    }

    public function testSendsWhenLoggerFails(): void
    {
        $this->storage->failure = new \RuntimeException('Connection refused');
        $this->logFailure = new \LogicException('Logger failed');

        $this->assertTrue($this->throttle()->decide('a')->send);
    }

    #[DataProvider('optionsOutOfRange')]
    public function testSendsEveryEventAndLogsOnceWithOptionOutOfRange(string $option, string $prefix, int $window, int $perFingerprint, int $maxPerWindow, int $fallbackMaxPerWindow): void
    {
        $throttle = $this->throttle($perFingerprint, $maxPerWindow, $fallbackMaxPerWindow, $prefix, $window);

        $this->assertSame([true, true, true], $this->sends($throttle, 'a', 3));
        $this->assertSame(0, $this->storage->calls);
        $this->assertCount(1, $this->logs);
        $this->assertSame($option, $this->logs[0][1]['options']);
    }

    /**
     * @return iterable<string, array{string, string, int, int, int, int}>
     */
    public static function optionsOutOfRange(): iterable
    {
        yield 'empty prefix' => ['prefix', '', 60, 50, 300, 50];
        yield 'zero window' => ['window', 'app', 0, 50, 300, 50];
        yield 'zero per_fingerprint' => ['per_fingerprint', 'app', 60, 0, 300, 50];
        yield 'negative max_per_window' => ['max_per_window', 'app', 60, 50, -1, 50];
        yield 'zero fallback_max_per_window' => ['fallback_max_per_window', 'app', 60, 50, 300, 0];
    }

    public function testEventRaisedWhileDecidingIsSentWithoutStorage(): void
    {
        $throttle = $this->throttle();
        /** @var list<Decision> $nested */
        $nested = [];
        $this->storage->onCall = static function () use ($throttle, &$nested): void {
            $nested[] = $throttle->decide('nested');
        };

        $this->assertTrue($throttle->decide('a')->send);
        $this->assertSame(1, $this->storage->calls);
        $this->assertSame([true], array_map(static fn (Decision $decision): bool => $decision->send, $nested));
    }

    /**
     * Another process with the same storage decides the fingerprint while an event of fingerprint "a", dropped by
     * the per-fingerprint limit, is between its two round trips and still counts in both window counters.
     *
     * @return array{Throttle, Decision}
     */
    private function decideWhileDroppedEventIsBetweenItsRoundTrips(string $fingerprint): array
    {
        $throttle = $this->throttle(perFingerprint: 1, maxPerWindow: 2);
        $otherThrottle = $this->throttle(perFingerprint: 1, maxPerWindow: 2);
        $throttle->decide('a');
        $calls = 0;
        $concurrent = null;
        $this->storage->onCall = static function () use ($otherThrottle, $fingerprint, &$calls, &$concurrent): void {
            // The second call is the second round trip of the dropped event.
            if (++$calls === 2) {
                $concurrent = $otherThrottle->decide($fingerprint);
            }
        };

        $this->assertFalse($throttle->decide('a')->send);
        $this->storage->onCall = null;
        $this->assertInstanceOf(Decision::class, $concurrent);

        return [$throttle, $concurrent];
    }

    private function throttle(int $perFingerprint = 50, int $maxPerWindow = 300, int $fallbackMaxPerWindow = 50, string $prefix = 'app', int $window = 60): Throttle
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
            if ($this->logFailure instanceof \Throwable) {
                throw $this->logFailure;
            }

            $this->logs[] = [(string) $message, $context];
        });

        return new Throttle($this->storage, $prefix, $window, $perFingerprint, $maxPerWindow, $fallbackMaxPerWindow, $logger, fn (): int => $this->now);
    }

    /**
     * @return list<bool>
     */
    private function sends(Throttle $throttle, string $fingerprint, int $times): array
    {
        $sends = [];

        for ($i = 0; $i < $times; ++$i) {
            $sends[] = $throttle->decide($fingerprint)->send;
        }

        return $sends;
    }
}
