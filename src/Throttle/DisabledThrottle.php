<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Throttle;

/**
 * @internal
 */
final class DisabledThrottle implements ThrottleInterface
{
    public function decide(string $fingerprint): Decision
    {
        return Decision::send();
    }
}
