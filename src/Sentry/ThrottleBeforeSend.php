<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Sentry;

use Sentry\Event;
use ThreeBRS\SentryThrottleBundle\Throttle\Throttle;
use ThreeBRS\SentryThrottleBundle\Throttle\ThrottleInterface;

/**
 * Sentry before_send callback that drops events over the limits. A project with its own before_send calls it last,
 * after its own filtering, so that only events that are really sent are counted.
 */
final class ThrottleBeforeSend
{
    /**
     * Logger the Sentry Monolog handler gives to events from the fail-open log channel.
     */
    private const FAIL_OPEN_LOGGER = 'monolog.' . Throttle::LOG_CHANNEL;

    public function __construct(
        private readonly ThrottleInterface $throttle,
        private readonly EventFingerprinter $fingerprinter,
    ) {
    }

    public function __invoke(Event $event): ?Event
    {
        try {
            if ($event->getLogger() === self::FAIL_OPEN_LOGGER) {
                return null;
            }

            $decision = $this->throttle->decide($this->fingerprinter->fingerprint($event));

            if (! $decision->send) {
                return null;
            }

            foreach ($decision->tags() as $key => $value) {
                $event->setTag($key, $value);
            }
        } catch (\Throwable) {
        }

        return $event;
    }
}
