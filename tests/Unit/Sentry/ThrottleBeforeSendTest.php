<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Sentry;

use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use ThreeBRS\SentryThrottleBundle\Fingerprint\Fingerprinter;
use ThreeBRS\SentryThrottleBundle\Sentry\EventFingerprinter;
use ThreeBRS\SentryThrottleBundle\Sentry\ThrottleBeforeSend;
use ThreeBRS\SentryThrottleBundle\Tests\Unit\Throttle\ThrottleSpy;
use ThreeBRS\SentryThrottleBundle\Throttle\Decision;

#[RequiresMethod(Event::class, 'createEvent')]
final class ThrottleBeforeSendTest extends TestCase
{
    public function testReturnsEventThatIsWithinLimits(): void
    {
        $event = $this->event();

        $this->assertSame($event, $this->beforeSend(new ThrottleSpy(Decision::send()))($event));
        $this->assertSame([], $event->getTags());
    }

    public function testDropsEventOverLimits(): void
    {
        $this->assertNull($this->beforeSend(new ThrottleSpy(Decision::drop()))($this->event()));
    }

    public function testTagsFirstEventSentAfterDrops(): void
    {
        $event = $this->event();

        $this->beforeSend(new ThrottleSpy(Decision::send(7)))($event);

        $this->assertSame([
            'throttled' => 'true',
            'throttle_occurrences' => '7',
        ], $event->getTags());
    }

    public function testDecidesByEventFingerprint(): void
    {
        $throttle = new ThrottleSpy(Decision::send());
        $event = $this->event();

        $this->beforeSend($throttle)($event);

        $this->assertSame([(new EventFingerprinter(new Fingerprinter()))->fingerprint($event)], $throttle->fingerprints);
    }

    public function testDropsFailOpenLogWithoutDeciding(): void
    {
        $throttle = new ThrottleSpy(Decision::send());
        $event = $this->event();
        $event->setLogger('monolog.sentry_throttle');

        $this->assertNull($this->beforeSend($throttle)($event));
        $this->assertSame([], $throttle->fingerprints);
    }

    public function testSendsEventWhenThrottlingFails(): void
    {
        $event = $this->event();

        $this->assertSame($event, $this->beforeSend(new ThrottleSpy(new \LogicException('Failed')))($event));
    }

    private function beforeSend(ThrottleSpy $throttle): ThrottleBeforeSend
    {
        return new ThrottleBeforeSend($throttle, new EventFingerprinter(new Fingerprinter()));
    }

    private function event(): Event
    {
        $event = Event::createEvent();
        $event->setMessage('Import failed');

        return $event;
    }
}
