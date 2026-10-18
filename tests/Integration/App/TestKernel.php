<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Integration\App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use ThreeBRS\SentryThrottleBundle\ThreeBRSSentryThrottleBundle;

final class TestKernel extends Kernel
{
    /**
     * @param array<string, mixed> $bundleConfig
     */
    public function __construct(
        private readonly array $bundleConfig = [],
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ThreeBRSSentryThrottleBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
            ]);
            $container->loadFromExtension('three_brs_sentry_throttle', $this->bundleConfig);
        });
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/test/' . md5(serialize($this->bundleConfig));
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }
}
