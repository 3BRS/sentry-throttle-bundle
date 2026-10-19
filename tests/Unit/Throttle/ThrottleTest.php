<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Throttle;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
        $this->assertSame(3, $this->storage->calls, 'sent: fingerprint, global and dropped counter');

        $throttle->decide('a');
        $this->assertSame(3 + 2, $this->storage->calls, 'dropped by the fingerprint limit: fingerprint and dropped counter');

        $throttle->decide('b');
        $this->assertSame(3 + 2 + 3, $this->storage->calls, 'dropped by the global cap: fingerprint, global and dropped counter');
    }

    public function testKeysContainPrefixWindowAndFingerprint(): void
    {
        $throttle = $this->throttle(perFingerprint: 1);
        $this->sends($throttle, 'a', 2);

        $this->assertSame([
            'sentry_throttle:app:fp:1800000000:a' => 2,
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
        $this->assertSame(1 + 3 + 2, $this->storage->calls);
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
        $this->assertSame(50, $context['limit']);
        $this->assertSame(60, $context['window']);
        $this->assertSame('Connection refused', $context['error']);
        $this->assertSame(\RuntimeException::class, $context['error_class']);
        $this->assertArrayNotHasKey('exception', $context);
    }

    public function testSendsWhenLoggerFails(): void
    {
        $this->storage->failure = new \RuntimeException('Connection refused');
        $this->logFailure = new \LogicException('Logger failed');

        $this->assertTrue($this->throttle()->decide('a')->send);
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
        $this->assertSame(3, $this->storage->calls);
        $this->assertSame([true, true, true], array_map(static fn (Decision $decision): bool => $decision->send, $nested));
    }

    private function throttle(int $perFingerprint = 50, int $maxPerWindow = 300, int $fallbackMaxPerWindow = 50): Throttle
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
            if ($this->logFailure instanceof \Throwable) {
                throw $this->logFailure;
            }

            $this->logs[] = [(string) $message, $context];
        });

        return new Throttle($this->storage, 'app', 60, $perFingerprint, $maxPerWindow, $fallbackMaxPerWindow, $logger, fn (): int => $this->now);
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
