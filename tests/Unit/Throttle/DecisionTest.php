<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Throttle;

use PHPUnit\Framework\TestCase;
use ThreeBRS\SentryThrottleBundle\Throttle\Decision;

final class DecisionTest extends TestCase
{
    public function testSentEventAfterDropsHasOccurrenceTags(): void
    {
        $this->assertSame([
            'throttled' => 'true',
            'throttle_occurrences' => '12',
        ], Decision::send(12)->tags());
    }

    public function testSentEventWithoutDropsHasNoTags(): void
    {
        $this->assertSame([], Decision::send()->tags());
    }

    public function testDroppedEventHasNoTags(): void
    {
        $decision = Decision::drop();

        $this->assertFalse($decision->send);
        $this->assertSame([], $decision->tags());
    }
}
