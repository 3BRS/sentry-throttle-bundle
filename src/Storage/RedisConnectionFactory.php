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
        // Read after the last "?": parse_url() rejects unix sockets and passwords with "/", "#" or "?".
        $query = [];
        $queryStart = strrpos($dsn, '?');

        if ($queryStart !== false) {
            parse_str(substr($dsn, $queryStart + 1), $query);
        }

        $effectiveTimeout = isset($query['timeout']) && \is_string($query['timeout']) ? (float) $query['timeout'] : $timeout;
        $effectiveReadTimeout = isset($query['read_timeout']) && \is_string($query['read_timeout']) ? (float) $query['read_timeout'] : $readTimeout;

        // Values from environment variables are not validated when the container is compiled. phpredis takes 0 as
        // PHP's default_socket_timeout.
        if ($effectiveTimeout <= 0 || $effectiveReadTimeout <= 0) {
            throw new StorageException(\sprintf('Redis "timeout" and "read_timeout" must be greater than 0, got %s and %s.', $effectiveTimeout, $effectiveReadTimeout));
        }

        $options = [
            'timeout' => $timeout,
            'read_timeout' => $readTimeout,
        ];

        // Without ext-redis Symfony would take Relay when its extension is loaded; Relay is not supported.
        if (! \extension_loaded('redis')) {
            $options['class'] = Client::class;
        }

        return RedisAdapter::createConnection($dsn, $options);
    }
}
