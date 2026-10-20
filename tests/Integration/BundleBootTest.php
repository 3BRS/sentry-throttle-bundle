<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Integration;

use ThreeBRS\SentryThrottleBundle\DependencyInjection\ThreeBRSSentryThrottleExtension;
use ThreeBRS\SentryThrottleBundle\Throttle\DisabledThrottle;

final class BundleBootTest extends IntegrationTestCase
{
    public function testKernelBootsWithThrottleOfBundle(): void
    {
        $container = $this->bootKernel([
            'enabled' => false,
        ]);

        $this->assertInstanceOf(DisabledThrottle::class, $container->get(ThreeBRSSentryThrottleExtension::THROTTLE));
    }
}
