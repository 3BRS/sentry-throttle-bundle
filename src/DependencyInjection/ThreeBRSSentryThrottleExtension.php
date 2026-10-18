<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class ThreeBRSSentryThrottleExtension extends Extension
{
    public function getAlias(): string
    {
        return 'three_brs_sentry_throttle';
    }

    /**
     * @param array<array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $this->processConfiguration(new Configuration(), $configs);
    }
}
