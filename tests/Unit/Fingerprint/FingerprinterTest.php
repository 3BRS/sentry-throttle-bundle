<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Fingerprint;

use PHPUnit\Framework\TestCase;
use ThreeBRS\SentryThrottleBundle\Fingerprint\Fingerprinter;

final class FingerprinterTest extends TestCase
{
    public function testFingerprintIsHexHash(): void
    {
        $fingerprinter = new Fingerprinter();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fingerprinter->forThrowable(new \RuntimeException('Failed')));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $fingerprinter->forMessage('error', 'Failed'));
    }

    public function testSameErrorWithDifferentValuesSharesFingerprint(): void
    {
        $fingerprinter = new Fingerprinter();

        $this->assertSame(
            $fingerprinter->forThrowable($this->throwable('Order 123 not found')),
            $fingerprinter->forThrowable($this->throwable('Order 456 not found')),
        );
    }

    public function testMessageTextIsPartOfFingerprint(): void
    {
        $fingerprinter = new Fingerprinter();

        $this->assertNotSame(
            $fingerprinter->forThrowable($this->throwable('Order 123 not found')),
            $fingerprinter->forThrowable($this->throwable('Order 123 is locked')),
        );
    }

    public function testExceptionClassIsPartOfFingerprint(): void
    {
        $fingerprinter = new Fingerprinter();

        $this->assertNotSame(
            $fingerprinter->forException(\RuntimeException::class, 'Failed', '/src/Foo.php', 10),
            $fingerprinter->forException(\LogicException::class, 'Failed', '/src/Foo.php', 10),
        );
    }

    public function testCrashSiteIsPartOfFingerprint(): void
    {
        $fingerprinter = new Fingerprinter();

        $this->assertNotSame(
            $fingerprinter->forException(\RuntimeException::class, 'Failed', '/src/Foo.php', 10),
            $fingerprinter->forException(\RuntimeException::class, 'Failed', '/src/Foo.php', 11),
        );
        $this->assertNotSame(
            $fingerprinter->forException(\RuntimeException::class, 'Failed', '/src/Foo.php', 10),
            $fingerprinter->forException(\RuntimeException::class, 'Failed', '/src/Bar.php', 10),
        );
        $this->assertNotSame(
            $fingerprinter->forException(\RuntimeException::class, 'Failed', '/src/Foo.php', 10),
            $fingerprinter->forException(\RuntimeException::class, 'Failed', null, null),
        );
    }

    public function testRootCauseDecidesFingerprint(): void
    {
        $fingerprinter = new Fingerprinter();
        $rootCause = $this->throwable('Connection refused');

        $this->assertSame(
            $fingerprinter->forThrowable($rootCause),
            $fingerprinter->forThrowable(new \LogicException('Handling message 1 failed', 0, $rootCause)),
        );
        $this->assertSame(
            $fingerprinter->forThrowable(new \RuntimeException('Request failed', 0, new \LogicException('Handling message 2 failed', 0, $rootCause))),
            $fingerprinter->forThrowable(new \LogicException('Handling message 3 failed', 0, $rootCause)),
        );
    }

    public function testThrowableMatchesExceptionWithItsData(): void
    {
        $fingerprinter = new Fingerprinter(\dirname(__DIR__, 3));
        $throwable = $this->throwable('Order 123 not found');

        $this->assertSame(
            $fingerprinter->forThrowable($throwable),
            $fingerprinter->forException($throwable::class, $throwable->getMessage(), $throwable->getFile(), $throwable->getLine()),
        );
    }

    public function testProjectDirIsStrippedFromCrashSite(): void
    {
        $this->assertSame(
            (new Fingerprinter('/srv/releases/1'))->forException(\RuntimeException::class, 'Failed', '/srv/releases/1/src/Foo.php', 10),
            (new Fingerprinter('/srv/releases/2'))->forException(\RuntimeException::class, 'Failed', '/srv/releases/2/src/Foo.php', 10),
        );
        $this->assertSame(
            (new Fingerprinter('/srv/releases/1'))->forException(\RuntimeException::class, 'Failed', '/srv/releases/1/src/Foo.php', 10),
            (new Fingerprinter())->forException(\RuntimeException::class, 'Failed', '/src/Foo.php', 10),
        );
    }

    public function testMessageWithDifferentValuesSharesFingerprint(): void
    {
        $fingerprinter = new Fingerprinter();

        $this->assertSame(
            $fingerprinter->forMessage('error', 'Import of 120 rows failed'),
            $fingerprinter->forMessage('error', 'Import of 7 rows failed'),
        );
    }

    public function testLevelIsPartOfMessageFingerprint(): void
    {
        $fingerprinter = new Fingerprinter();

        $this->assertNotSame(
            $fingerprinter->forMessage('error', 'Import failed'),
            $fingerprinter->forMessage('warning', 'Import failed'),
        );
    }

    private function throwable(string $message): \RuntimeException
    {
        return new \RuntimeException($message);
    }
}
