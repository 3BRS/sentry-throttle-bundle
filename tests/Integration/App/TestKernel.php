<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Integration\App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use ThreeBRS\SentryThrottleBundle\ThreeBRSSentryThrottleBundle;

final class TestKernel extends Kernel
{
    private readonly string $cacheKey;

    /**
     * @param array<string, mixed> $bundleConfig
     * @param list<BundleInterface> $extraBundles
     * @param (\Closure(ContainerBuilder): void)|null $configure
     */
    public function __construct(
        private readonly array $bundleConfig = [],
        private readonly array $extraBundles = [],
        private readonly ?\Closure $configure = null,
    ) {
        $this->cacheKey = bin2hex(random_bytes(8));

        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ThreeBRSSentryThrottleBundle();
        yield from $this->extraBundles;
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

            if ($this->configure instanceof \Closure) {
                ($this->configure)($container);
            }
        });
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/test/' . $this->cacheKey;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    protected function build(ContainerBuilder $container): void
    {
        // Unused private services would be removed; tests get them from the container directly.
        $container->addCompilerPass(new class() implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'three_brs_sentry_throttle.')) {
                        $definition->setPublic(true);
                    }
                }
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
    }
}
