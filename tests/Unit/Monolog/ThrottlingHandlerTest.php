<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Monolog;

use Monolog\Handler\BufferHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use ThreeBRS\SentryThrottleBundle\Fingerprint\Fingerprinter;
use ThreeBRS\SentryThrottleBundle\Monolog\ThrottlingHandler;
use ThreeBRS\SentryThrottleBundle\Tests\Unit\Throttle\ArrayStorage;
use ThreeBRS\SentryThrottleBundle\Tests\Unit\Throttle\ThrottleSpy;
use ThreeBRS\SentryThrottleBundle\Throttle\Decision;
use ThreeBRS\SentryThrottleBundle\Throttle\Throttle;
use ThreeBRS\SentryThrottleBundle\Throttle\ThrottleInterface;

#[RequiresMethod(Logger::class, 'pushHandler')]
final class ThrottlingHandlerTest extends TestCase
{
    private TestHandler $sentry;

    protected function setUp(): void
    {
        $this->sentry = new TestHandler(Logger::ERROR);
    }

    public function testPassesRecordWithinLimits(): void
    {
        $this->logger(new ThrottleSpy(Decision::send()))->error('Import failed');

        $this->assertCount(1, $this->sentry->getRecords());
        $this->assertArrayNotHasKey('throttled', $this->field($this->sentry, 'context'));
    }

    public function testDropsRecordOverLimits(): void
    {
        $this->logger(new ThrottleSpy(Decision::drop()))->error('Import failed');

        $this->assertSame([], $this->sentry->getRecords());
    }

    public function testTagsFirstRecordSentAfterDropsInContext(): void
    {
        $this->logger(new ThrottleSpy(Decision::send(4)))->error('Import failed');

        $context = $this->field($this->sentry, 'context');
        $this->assertSame('true', $context['throttled'] ?? null);
        $this->assertSame('4', $context['throttle_occurrences'] ?? null);
        $this->assertArrayNotHasKey('throttled', $this->field($this->sentry, 'extra'));
    }

    public function testTagsFirstRecordSentAfterDropsInExtra(): void
    {
        $this->logger(new ThrottleSpy(Decision::send(4)), ThrottlingHandler::TAGS_IN_EXTRA)->error('Import failed');

        $extra = $this->field($this->sentry, 'extra');
        $this->assertSame('true', $extra['throttled'] ?? null);
        $this->assertSame('4', $extra['throttle_occurrences'] ?? null);
        $this->assertArrayNotHasKey('throttled', $this->field($this->sentry, 'context'));
    }

    /**
     * @param ThrottlingHandler::TAGS_IN_* $tagsIn
     */
    #[DataProvider('tagFields')]
    public function testDoesNotTagRecordOfOtherHandlers(string $tagsIn): void
    {
        $other = new TestHandler();
        $logger = new Logger('app', [
            new ThrottlingHandler($this->sentry, new ThrottleSpy(Decision::send(4)), new Fingerprinter(), $tagsIn),
            $other,
        ]);

        $logger->error('Import failed');

        $this->assertArrayHasKey('throttled', $this->field($this->sentry, $tagsIn));
        $this->assertArrayNotHasKey('throttled', $this->field($other, 'context'));
        $this->assertArrayNotHasKey('throttled', $this->field($other, 'extra'));
    }

    /**
     * @return iterable<string, array{ThrottlingHandler::TAGS_IN_*}>
     */
    public static function tagFields(): iterable
    {
        yield 'context' => [ThrottlingHandler::TAGS_IN_CONTEXT];
        yield 'extra' => [ThrottlingHandler::TAGS_IN_EXTRA];
    }

    /**
     * @param ThrottlingHandler::TAGS_IN_* $tagsIn
     */
    #[DataProvider('tagFields')]
    #[RequiresMethod(LogRecord::class, 'with')]
    public function testLeavesHandledLogRecordUnchanged(string $tagsIn): void
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'app', Level::Error, 'Import failed');

        (new ThrottlingHandler($this->sentry, new ThrottleSpy(Decision::send(4)), new Fingerprinter(), $tagsIn))->handle($record);

        $this->assertSame([], $record->context);
        $this->assertSame([], $record->extra);
        $this->assertArrayHasKey('throttled', $this->field($this->sentry, $tagsIn));
    }

    public function testFingerprintsExceptionFromContext(): void
    {
        $throttle = new ThrottleSpy(Decision::send());
        $exception = new \RuntimeException('Order 123 not found');

        $this->logger($throttle)->error('Order lookup failed', [
            'exception' => $exception,
        ]);

        $this->assertSame([(new Fingerprinter())->forThrowable($exception)], $throttle->fingerprints);
    }

    public function testFingerprintsMessageWithLevel(): void
    {
        $throttle = new ThrottleSpy(Decision::send());

        $this->logger($throttle)->error('Import of 7 rows failed');

        $this->assertSame([(new Fingerprinter())->forMessage('ERROR', 'Import of 7 rows failed')], $throttle->fingerprints);
    }

    public function testIgnoresRecordsTheWrappedHandlerDoesNotHandle(): void
    {
        $throttle = new ThrottleSpy(Decision::send());

        $this->logger($throttle)->warning('Import is slow');

        $this->assertSame([], $throttle->fingerprints);
        $this->assertSame([], $this->sentry->getRecords());
    }

    public function testDropsFailOpenLogWithoutDeciding(): void
    {
        $throttle = new ThrottleSpy(Decision::send());

        $this->logger($throttle, channel: Throttle::LOG_CHANNEL)->error('Sentry throttle cannot use Redis');

        $this->assertSame([], $throttle->fingerprints);
        $this->assertSame([], $this->sentry->getRecords());
    }

    #[DataProvider('bubbling')]
    public function testDroppedRecordBubblesLikeRecordOfWrappedHandler(bool $bubble, int $recordsOfNextHandler): void
    {
        $next = new TestHandler();
        $logger = new Logger('app', [
            new ThrottlingHandler(new TestHandler(Logger::ERROR, $bubble), new ThrottleSpy(Decision::drop()), new Fingerprinter()),
            $next,
        ]);

        $logger->error('Import failed');

        $this->assertCount($recordsOfNextHandler, $next->getRecords());
    }

    /**
     * @return iterable<string, array{bool, int}>
     */
    public static function bubbling(): iterable
    {
        yield 'bubbling wrapped handler' => [true, 1];
        yield 'not bubbling wrapped handler' => [false, 0];
    }

    public function testPassesRecordWhenThrottlingFails(): void
    {
        $this->logger(new ThrottleSpy(new \LogicException('Failed')))->error('Import failed');

        $this->assertCount(1, $this->sentry->getRecords());
    }

    public function testThrottlesBatch(): void
    {
        $buffer = new BufferHandler(new ThrottlingHandler(
            $this->sentry,
            new Throttle(new ArrayStorage(), 'app', 60, 1, 300, 50, clock: static fn (): int => 1_800_000_000),
            new Fingerprinter(),
        ));
        $logger = new Logger('app', [$buffer]);

        $logger->error('Import failed');
        $logger->error('Import failed');
        $logger->error('Export failed');
        $logger->info('Import started');
        $buffer->flush();

        $this->assertSame(['Import failed', 'Export failed'], array_column($this->sentry->getRecords(), 'message'));
    }

    /**
     * The context or extra of the first record a handler got.
     *
     * @return array<mixed>
     */
    private function field(TestHandler $handler, string $field): array
    {
        $value = $handler->getRecords()[0][$field] ?? null;
        $this->assertIsArray($value);

        return $value;
    }

    /**
     * @param ThrottlingHandler::TAGS_IN_* $tagsIn
     */
    private function logger(ThrottleInterface $throttle, string $tagsIn = ThrottlingHandler::TAGS_IN_CONTEXT, string $channel = 'app'): Logger
    {
        return new Logger($channel, [new ThrottlingHandler($this->sentry, $throttle, new Fingerprinter(), $tagsIn)]);
    }
}
