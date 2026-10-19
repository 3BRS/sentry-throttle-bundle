<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Predis\Client;
use ThreeBRS\SentryThrottleBundle\Storage\PredisStorage;
use ThreeBRS\SentryThrottleBundle\Storage\StorageException;

final class PredisStorageTest extends TestCase
{
    public function testRejectsClusterConnection(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Redis Cluster is not supported.');

        new PredisStorage(new Client(['tcp://127.0.0.1:7000', 'tcp://127.0.0.1:7001'], [
            'cluster' => 'redis',
        ]));
    }
}
