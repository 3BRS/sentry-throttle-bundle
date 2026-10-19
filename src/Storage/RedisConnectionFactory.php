<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Storage;

use Predis\Client;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * @internal
 */
final class RedisConnectionFactory
{
    /**
     * Uses phpredis when the extension is loaded, Predis otherwise. Query parameters of the DSN override the timeouts.
     */
    public static function create(string $dsn, float $timeout, float $readTimeout): object
    {
        $options = [
            'timeout' => $timeout,
            'read_timeout' => $readTimeout,
        ];

        if (! \extension_loaded('redis')) {
            $options['class'] = Client::class;
        }

        return RedisAdapter::createConnection($dsn, $options);
    }
}
