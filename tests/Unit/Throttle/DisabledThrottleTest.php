<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Throttle;

use PHPUnit\Framework\TestCase;
use ThreeBRS\SentryThrottleBundle\Throttle\DisabledThrottle;

final class DisabledThrottleTest extends TestCase
{
    public function testSendsEveryEventWithoutTags(): void
    {
        $throttle = new DisabledThrottle();

        foreach (['a', 'a', 'b'] as $fingerprint) {
            $decision = $throttle->decide($fingerprint);

            $this->assertTrue($decision->send);
            $this->assertSame([], $decision->tags());
        }
    }
}
