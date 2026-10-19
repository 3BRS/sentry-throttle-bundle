<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Throttle;

/**
 * @internal
 */
final class Decision
{
    private function __construct(
        public readonly bool $send,
        public readonly int $droppedSinceLastSent = 0,
    ) {
    }

    public static function send(int $droppedSinceLastSent = 0): self
    {
        return new self(true, $droppedSinceLastSent);
    }

    public static function drop(): self
    {
        return new self(false);
    }

    /**
     * Tags for the first event of a fingerprint sent after drops; empty otherwise.
     *
     * @return array<string, string>
     */
    public function tags(): array
    {
        if (! $this->send || $this->droppedSinceLastSent === 0) {
            return [];
        }

        return [
            'throttled' => 'true',
            'throttle_occurrences' => (string) $this->droppedSinceLastSent,
        ];
    }
}
