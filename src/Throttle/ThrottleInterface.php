<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Throttle;

/**
 * @internal
 */
interface ThrottleInterface
{
    /**
     * Never throws; when the decision cannot be made, the event is sent.
     */
    public function decide(string $fingerprint): Decision;
}
