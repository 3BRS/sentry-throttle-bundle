<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Fingerprint;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ThreeBRS\SentryThrottleBundle\Fingerprint\MessageNormalizer;

final class MessageNormalizerTest extends TestCase
{
    #[DataProvider('messages')]
    public function testNormalizesVariableParts(string $message, string $expected): void
    {
        $this->assertSame($expected, (new MessageNormalizer())->normalize($message));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messages(): iterable
    {
        yield 'integer' => ['Order 123 not found', 'Order # not found'];
        yield 'decimal and sign' => ['Value -1.5 is out of range', 'Value -#.# is out of range'];
        yield 'ip and port' => ['Connection to 10.0.0.1:6379 timed out', 'Connection to #.#.#.#:# timed out'];
        yield 'date and time' => ['Expired at 2026-10-19 12:00:00', 'Expired at #-#-# #:#:#'];
        yield 'token with digit' => ['Cache key user_42abc is invalid', 'Cache key user_# is invalid'];
        yield 'hex hash' => ['Invalid token d41d8cd98f00b204e9800998ecf8427e', 'Invalid token #'];
        yield 'hex number' => ['Segfault at 0x7fff5fbff8c0', 'Segfault at #'];
        yield 'sql state' => ['SQLSTATE[HY000] [2002] Connection refused', 'SQLSTATE[#] [#] Connection refused'];
        yield 'uuid' => ['Order 550e8400-e29b-41d4-a716-446655440000 not found', 'Order # not found'];
        yield 'uppercase uuid' => ['Order 550E8400-E29B-41D4-A716-446655440000 not found', 'Order # not found'];
        yield 'uuid with groups without a digit' => ['Order abcdef12-abcd-4bcd-abcd-abcdefabcdef not found', 'Order # not found'];
        yield 'words of hex letters' => ['Facade added a decade of beef', 'Facade added a decade of beef'];
        yield 'invalid utf-8' => ["Bad \xff\xfe byte 12", "Bad \xff\xfe byte #"];
        yield 'empty' => ['', ''];
    }

    public function testNormalizesOnlyTheMessagePrefix(): void
    {
        $normalizer = new MessageNormalizer();
        $prefix = str_repeat('x', 10_000);

        $this->assertSame($normalizer->normalize($prefix . 'a'), $normalizer->normalize($prefix . 'b'));
    }
}
