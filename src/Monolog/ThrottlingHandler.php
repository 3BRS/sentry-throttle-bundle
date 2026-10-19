<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Monolog;

use Monolog\Handler\AbstractHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\HandlerWrapper;
use ThreeBRS\SentryThrottleBundle\Fingerprint\Fingerprinter;
use ThreeBRS\SentryThrottleBundle\Throttle\Decision;
use ThreeBRS\SentryThrottleBundle\Throttle\Throttle;
use ThreeBRS\SentryThrottleBundle\Throttle\ThrottleInterface;

/**
 * Wraps the handler that sends records to Sentry and drops records over the limits.
 */
final class ThrottlingHandler extends HandlerWrapper
{
    use CompatibilityThrottlingHandlerTrait;

    public const TAGS_IN_CONTEXT = 'context';

    public const TAGS_IN_EXTRA = 'extra';

    /**
     * @internal handlers are wrapped through the "monolog.handlers" option
     *
     * @param self::TAGS_IN_* $tagsIn record field the wrapped handler reads Sentry tags from
     */
    public function __construct(
        HandlerInterface $handler,
        private readonly ThrottleInterface $throttle,
        private readonly Fingerprinter $fingerprinter,
        private readonly string $tagsIn = self::TAGS_IN_CONTEXT,
    ) {
        parent::__construct($handler);
    }

    private function decision(string $channel, string $level, string $message, mixed $exception): Decision
    {
        try {
            // The bundle's own fail-open log must not reach Sentry.
            if ($channel === Throttle::LOG_CHANNEL) {
                return Decision::drop();
            }

            return $this->throttle->decide($exception instanceof \Throwable
                ? $this->fingerprinter->forThrowable($exception)
                : $this->fingerprinter->forMessage($level, $message));
        } catch (\Throwable) {
            return Decision::send();
        }
    }

    /**
     * For a dropped record handle() returns what the wrapped handler returns after handling one: whether to stop bubbling.
     */
    private function droppedRecordResult(): bool
    {
        return $this->handler instanceof AbstractHandler && ! $this->handler->getBubble();
    }
}
