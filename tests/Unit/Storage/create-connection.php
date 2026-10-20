<?php

declare(strict_types=1);

// Creates a connection from the DSN given as the first argument and prints its class; run with "php -n" to leave out
// ext-redis.

use ThreeBRS\SentryThrottleBundle\Storage\RedisConnectionFactory;

require \dirname(__DIR__, 3) . '/vendor/autoload.php';

echo get_debug_type(RedisConnectionFactory::create($argv[1] ?? '', 0.2, 0.2));
