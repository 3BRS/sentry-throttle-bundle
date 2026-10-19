<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Integration\Sentry;

use Sentry\Event;
use ThreeBRS\SentryThrottleBundle\Sentry\ThrottleBeforeSend;

/**
 * A project's own before_send that filters events first and lets the throttle decide last.
 */
final class OwnBeforeSend
{
    public function __construct(
        private readonly ThrottleBeforeSend $throttle,
    ) {
    }

    public function __invoke(Event $event): ?Event
    {
        if ($event->getMessage() === 'Ignored') {
            return null;
        }

        return ($this->throttle)($event);
    }
}
