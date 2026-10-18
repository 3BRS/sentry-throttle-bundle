<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ThreeBRS\SentryThrottleBundle\DependencyInjection\ThreeBRSSentryThrottleExtension;
use ThreeBRS\SentryThrottleBundle\ThreeBRSSentryThrottleBundle;

final class ThreeBRSSentryThrottleBundleTest extends TestCase
{
    public function testProvidesExtensionWithConfigAlias(): void
    {
        $extension = (new ThreeBRSSentryThrottleBundle())->getContainerExtension();

        $this->assertInstanceOf(ThreeBRSSentryThrottleExtension::class, $extension);
        $this->assertSame('three_brs_sentry_throttle', $extension->getAlias());
    }
}
