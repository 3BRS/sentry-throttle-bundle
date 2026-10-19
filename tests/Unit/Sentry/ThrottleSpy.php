<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Sentry;

use ThreeBRS\SentryThrottleBundle\Throttle\Decision;
use ThreeBRS\SentryThrottleBundle\Throttle\ThrottleInterface;

final class ThrottleSpy implements ThrottleInterface
{
    /**
     * @var list<string>
     */
    public array $fingerprints = [];

    public function __construct(
        private readonly Decision|\Throwable $result,
    ) {
    }

    public function decide(string $fingerprint): Decision
    {
        $this->fingerprints[] = $fingerprint;

        if ($this->result instanceof \Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}
