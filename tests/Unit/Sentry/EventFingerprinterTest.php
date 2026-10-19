<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Sentry;

use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Severity;
use ThreeBRS\SentryThrottleBundle\Fingerprint\Fingerprinter;
use ThreeBRS\SentryThrottleBundle\Sentry\EventFingerprinter;

#[RequiresMethod(Event::class, 'createEvent')]
final class EventFingerprinterTest extends TestCase
{
    private Fingerprinter $fingerprinter;

    protected function setUp(): void
    {
        $this->fingerprinter = new Fingerprinter($this->projectDir());
    }

    public function testFingerprintsRootCauseLikeThrowableFingerprint(): void
    {
        $rootCause = $this->rootCause('Order 123 not found');
        $event = $this->capture(new \LogicException('Handling message failed', 0, $rootCause));

        $this->assertSame($this->fingerprinter->forThrowable($rootCause), $this->eventFingerprinter()->fingerprint($event));
    }

    public function testSameRootCauseInDifferentWrappersSharesFingerprint(): void
    {
        $this->assertSame(
            $this->eventFingerprinter()->fingerprint($this->capture(new \LogicException('Handling message 1 failed', 0, $this->rootCause('Order 1 not found')))),
            $this->eventFingerprinter()->fingerprint($this->capture(new \RuntimeException('Request failed', 0, $this->rootCause('Order 2 not found')))),
        );
    }

    public function testFingerprintsExceptionWithoutStacktrace(): void
    {
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('Order 123 not found'))]);

        $this->assertSame(
            $this->fingerprinter->forException(\RuntimeException::class, 'Order 123 not found', null, null),
            $this->eventFingerprinter()->fingerprint($event),
        );
    }

    public function testFingerprintsMessageTemplateWithLevel(): void
    {
        $event = Event::createEvent();
        $event->setLevel(Severity::warning());
        $event->setMessage('Import of %s rows failed', ['120'], 'Import of 120 rows failed');

        $this->assertSame(
            $this->fingerprinter->forMessage('warning', 'Import of %s rows failed'),
            $this->eventFingerprinter()->fingerprint($event),
        );
    }

    public function testFingerprintsEventWithoutMessageOrLevel(): void
    {
        $this->assertSame($this->fingerprinter->forMessage('', ''), $this->eventFingerprinter()->fingerprint(Event::createEvent()));
    }

    private function eventFingerprinter(): EventFingerprinter
    {
        return new EventFingerprinter($this->fingerprinter);
    }

    private function capture(\Throwable $exception): Event
    {
        $captured = null;
        ClientBuilder::create([
            'dsn' => 'http://public@example.com/1',
            'default_integrations' => false,
            // As sentry-symfony configures it: frame paths become relative to the project dir.
            'prefixes' => [$this->projectDir()],
            'before_send' => static function (Event $event) use (&$captured): ?Event {
                $captured = $event;

                return null;
            },
        ])->getClient()->captureException($exception);

        $this->assertInstanceOf(Event::class, $captured);

        return $captured;
    }

    private function rootCause(string $message): \RuntimeException
    {
        return new \RuntimeException($message);
    }

    private function projectDir(): string
    {
        return \dirname(__DIR__, 3);
    }
}
