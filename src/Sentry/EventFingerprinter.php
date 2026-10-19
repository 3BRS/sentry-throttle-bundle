<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Sentry;

use Sentry\Event;
use ThreeBRS\SentryThrottleBundle\Fingerprint\Fingerprinter;

/**
 * @internal
 */
final class EventFingerprinter
{
    public function __construct(
        private readonly Fingerprinter $fingerprinter,
    ) {
    }

    public function fingerprint(Event $event): string
    {
        $exceptions = $event->getExceptions();

        if ($exceptions === []) {
            return $this->fingerprinter->forMessage((string) $event->getLevel(), $event->getMessage() ?? $event->getMessageFormatted() ?? '');
        }

        // The SDK lists the thrown exception first and its previous exceptions after it, so the last one is the root cause.
        $rootCause = end($exceptions);
        $frames = $rootCause->getStacktrace()?->getFrames() ?? [];
        // The last frame is the place where the exception was thrown.
        $crashSite = end($frames) ?: null;

        return $this->fingerprinter->forException($rootCause->getType(), $rootCause->getValue(), $crashSite?->getFile(), $crashSite?->getLine());
    }
}
