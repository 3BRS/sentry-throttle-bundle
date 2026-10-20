<?php

declare(strict_types=1);

namespace ThreeBRS\SentryThrottleBundle\Storage;

/**
 * The storage cannot be used for a moment, e.g. while the application has a transaction open on a shared client;
 * Redis has not failed.
 *
 * @internal
 */
final class StorageBusyException extends \RuntimeException
{
}
