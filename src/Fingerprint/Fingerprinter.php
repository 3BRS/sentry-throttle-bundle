<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Fingerprint;

/**
 * @internal
 */
final class Fingerprinter
{
    /**
     * @param string $projectDir prefix stripped from file paths, so the fingerprint does not depend on the release directory
     */
    public function __construct(
        private readonly string $projectDir = '',
        private readonly MessageNormalizer $normalizer = new MessageNormalizer(),
    ) {
    }

    /**
     * Fingerprints the root cause, i.e. the innermost previous throwable.
     */
    public function forThrowable(\Throwable $throwable): string
    {
        while (($previous = $throwable->getPrevious()) instanceof \Throwable) {
            $throwable = $previous;
        }

        return $this->forException($throwable::class, $throwable->getMessage(), $throwable->getFile(), $throwable->getLine());
    }

    public function forException(string $class, string $message, ?string $file, ?int $line): string
    {
        return $this->hash('exception', $class, $this->crashSite($file, $line), $this->normalizer->normalize($message));
    }

    public function forMessage(string $level, string $message): string
    {
        return $this->hash('message', $level, $this->normalizer->normalize($message));
    }

    private function crashSite(?string $file, ?int $line): string
    {
        if ($file === null) {
            return '';
        }

        if ($this->projectDir !== '' && str_starts_with($file, $this->projectDir)) {
            $file = substr($file, \strlen($this->projectDir));
        }

        return $file . ':' . $line;
    }

    private function hash(string ...$parts): string
    {
        return hash('xxh128', implode("\0", $parts));
    }
}
