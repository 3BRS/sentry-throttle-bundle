<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Fingerprint;

/**
 * @internal
 */
final class MessageNormalizer
{
    private const PLACEHOLDER = '#';

    /**
     * Longer messages are normalized by their prefix only; bounds the regex cost.
     */
    private const MAX_BYTES = 8192;

    private const PATTERNS = [
        // Runs first: UUID groups without a digit would survive the token rule.
        '/(?<![0-9A-Za-z])[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?![0-9A-Za-z])/i',
        // Alphanumeric token containing a digit: numbers, hashes, IDs.
        '/(?<![0-9A-Za-z])[A-Za-z]*+\d[0-9A-Za-z]*+/',
    ];

    public function normalize(string $message): string
    {
        $message = substr($message, 0, self::MAX_BYTES);

        return preg_replace(self::PATTERNS, self::PLACEHOLDER, $message) ?? $message;
    }
}
