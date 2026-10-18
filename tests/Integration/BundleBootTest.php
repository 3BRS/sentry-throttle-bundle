<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Integration;

use ThreeBRS\SentryThrottleBundle\ThreeBRSSentryThrottleBundle;

final class BundleBootTest extends IntegrationTestCase
{
    public function testKernelBootsWithBundle(): void
    {
        $bundles = $this->bootKernel()->getParameter('kernel.bundles');

        $this->assertIsArray($bundles);
        $this->assertSame(ThreeBRSSentryThrottleBundle::class, $bundles['ThreeBRSSentryThrottleBundle'] ?? null);
    }
}
