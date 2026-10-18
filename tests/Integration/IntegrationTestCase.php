<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use ThreeBRS\SentryThrottleBundle\Tests\Integration\App\TestKernel;

abstract class IntegrationTestCase extends TestCase
{
    private ?TestKernel $kernel = null;

    /**
     * @var callable|null
     */
    private $errorHandler;

    /**
     * @var callable|null
     */
    private $exceptionHandler;

    protected function setUp(): void
    {
        $this->errorHandler = $this->currentErrorHandler();
        $this->exceptionHandler = $this->currentExceptionHandler();
    }

    protected function tearDown(): void
    {
        if ($this->kernel instanceof TestKernel) {
            $cacheDir = $this->kernel->getCacheDir();
            $this->kernel->shutdown();
            $this->kernel = null;
            (new Filesystem())->remove($cacheDir);
        }

        // FrameworkBundle::boot() registers Symfony's ErrorHandler; PHPUnit reports handlers left behind as risky.
        for ($i = 0; $i < 10 && $this->currentErrorHandler() !== $this->errorHandler; ++$i) {
            restore_error_handler();
        }

        for ($i = 0; $i < 10 && $this->currentExceptionHandler() !== $this->exceptionHandler; ++$i) {
            restore_exception_handler();
        }
    }

    /**
     * @param array<string, mixed> $bundleConfig
     */
    protected function bootKernel(array $bundleConfig = []): ContainerInterface
    {
        $this->kernel = new TestKernel($bundleConfig);
        $this->kernel->boot();

        return $this->kernel->getContainer();
    }

    private function currentErrorHandler(): ?callable
    {
        $handler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $handler;
    }

    private function currentExceptionHandler(): ?callable
    {
        $handler = set_exception_handler(static function (): void {
        });
        restore_exception_handler();

        return $handler;
    }
}
