<p align="center">
    <a href="https://www.3brs.com" target="_blank">
        <img src="https://3brs1.fra1.cdn.digitaloceanspaces.com/3brs/logo/3BRS-logo-sylius-200.png"/>
    </a>
</p>

<h1 align="center">Sentry Throttle Bundle for Symfony</h1>

<p align="center">
    <a href="https://packagist.org/packages/3brs/sentry-throttle-bundle" title="License">
        <img src="https://img.shields.io/packagist/l/3brs/sentry-throttle-bundle.svg">
    </a>
    <a href="https://packagist.org/packages/3brs/sentry-throttle-bundle" title="Version">
        <img src="https://img.shields.io/packagist/v/3brs/sentry-throttle-bundle.svg">
    </a>
    <a href="https://github.com/3BRS/sentry-throttle-bundle/actions" title="Build Status">
        <img src="https://github.com/3BRS/sentry-throttle-bundle/actions/workflows/ci.yml/badge.svg">
    </a>
</p>

<p align="center">A Symfony bundle that limits how many events an application sends to <a href="https://sentry.io/">Sentry</a> when many errors occur in a short time, so that one failing part of the application cannot use up the Sentry quota. The counters live in Redis and are shared by all processes; when Redis is unavailable, events are still sent.</p>

## Features

- **Per-fingerprint limit** – at most `per_fingerprint` events with the same fingerprint per window (by default 50 per 60 seconds).
- **Global cap** – at most `max_per_window` events per window in total (by default 300). Only events within the per-fingerprint limit count towards it, so events of one error over its limit do not use up the cap for other errors.
- **Occurrence tags** – the first event of a fingerprint sent after drops gets the tags `throttled: true` and `throttle_occurrences: <events dropped since the previous sent one>`, so that you can search and alert on them in Sentry.
- **Fail-open** – when Redis is down or slow, or anything inside the bundle fails, the event is sent. The bundle never throws. A per-process cap still applies while Redis is unavailable.
- **Two integrations** – a `before_send` callback for the official Sentry SDK, and a wrapper for a Monolog handler that sends to Sentry without the SDK.
- **phpredis or Predis** – with its own connection and short timeouts, or with an existing Redis client service.

## Requirements

- PHP 8.1+
- Symfony 5.4, 6.x, 7.x or 8.x
- Redis and either the `redis` PHP extension (phpredis) or `predis/predis` 1.x (1.1.10+), 2.x or 3.x
- For the Sentry SDK integration: `sentry/sentry` 4.x (in a Symfony application with `sentry/sentry-symfony` 5.x)
- For the Monolog integration: `monolog/monolog` 2.x (2.5+) or 3.x

## Installation

### Step 1: Install the bundle

```bash
composer require 3brs/sentry-throttle-bundle
```

With Symfony Flex the bundle is registered during the installation, and the `cache:clear` that Flex runs afterwards fails with `"prefix" must be set when the throttle is enabled` until the configuration from Step 3 exists. Create that file before the installation, or run `cache:clear` again after Step 3.

### Step 2: Register the bundle

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    ThreeBRS\SentryThrottleBundle\ThreeBRSSentryThrottleBundle::class => ['all' => true],
];
```

### Step 3: Configure the bundle

Create `config/packages/three_brs_sentry_throttle.yaml`:

```yaml
three_brs_sentry_throttle:
    prefix: my-app
    redis: '%env(SENTRY_THROTTLE_REDIS_DSN)%' # or the id of an existing Redis client service

when@dev:
    three_brs_sentry_throttle:
        enabled: false

when@test:
    three_brs_sentry_throttle:
        enabled: false
```

- `prefix` is part of every Redis key, so that several applications can share one Redis. `prefix` and `redis` are required while the throttle is enabled.
- `enabled` decides which services are registered, so it cannot come from an environment variable; switch it off per environment as above. With `enabled: false` both integrations pass every event through and Redis is not used.

### Step 4: Connect the throttle to Sentry

Use the integration that matches how the application sends to Sentry, and only that one:

- the application uses the Sentry SDK (`sentry/sentry-symfony`) → [Sentry SDK integration](#sentry-sdk-integration);
- the application sends to Sentry through its own Monolog handler, without the SDK → [Monolog integration](#monolog-integration).

An application that sends log records through the Monolog handler of the Sentry SDK uses the Sentry SDK integration only. Wrapping that handler as well would count every event twice.

## Sentry SDK integration

Set the bundle's `before_send` callback in `config/packages/sentry.yaml`:

```yaml
sentry:
    options:
        before_send: 'ThreeBRS\SentryThrottleBundle\Sentry\ThrottleBeforeSend'
```

Every error event the SDK sends goes through it: exceptions captured by the SDK's listeners (including failed Messenger messages), fatal errors, records of the SDK's Monolog handler and direct `captureException()` or `captureMessage()` calls. Transactions use `before_send_transaction` and are not throttled. The SDK calls `before_send` after `sample_rate` and `ignore_exceptions`, so events dropped by them are not counted.

### Applications with their own before_send

Inject `ThrottleBeforeSend` (the service is autowirable) and call it as the last step, after your own filtering, so that only events that are really sent are counted:

```php
use Sentry\Event;
use Sentry\EventHint;
use ThreeBRS\SentryThrottleBundle\Sentry\ThrottleBeforeSend;

final class BeforeSend
{
    public function __construct(
        private readonly ThrottleBeforeSend $throttle,
    ) {
    }

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        // Your own filtering; return null to drop the event.

        return ($this->throttle)($event);
    }
}
```

## Monolog integration

For applications that send to Sentry through their own Monolog handler, without the Sentry SDK. List the id of the handler service that sends to Sentry:

```yaml
three_brs_sentry_throttle:
    monolog:
        handlers: ['app.sentry_handler']
        tags: context
```

- The bundle decorates each listed service with a throttling handler; the rest of the logging stays as it is.
- When another handler wraps the Sentry handler (for example a filtering handler), list the inner Sentry handler. The throttle then sits between the two.
- `tags` is the record field your handler reads Sentry tags from: `context` (default) or `extra`. The occurrence tags are added there.
- Only records the wrapped handler handles are counted (for example, records below its level are not).
- A record the throttle drops stops bubbling where a handled record would: when the wrapped handler extends `AbstractHandler` and has `bubble: false`. Other handlers, e.g. `GroupHandler` or `FilterHandler`, have no getter for it, so a dropped record goes on to the next handlers.
- The fingerprint comes from `context['exception']` when it holds an exception, otherwise from the level and the message.
- It works with Monolog 2 and 3.

## Redis

`redis` takes a DSN or the id of an existing Redis client service. A value from an environment variable or with a scheme (`redis://…`, `rediss://…`) is a DSN, any other value a service id – the same rule as for the providers of Symfony cache pools.

### Own connection (recommended)

With a DSN, the bundle creates its own connection with short timeouts:

```yaml
three_brs_sentry_throttle:
    redis: '%env(SENTRY_THROTTLE_REDIS_DSN)%'
```

The timeouts can be set in the long form:

```yaml
three_brs_sentry_throttle:
    redis:
        dsn: '%env(SENTRY_THROTTLE_REDIS_DSN)%'
        timeout: 0.2
        read_timeout: 0.2
```

- The DSN has the same format as for Symfony's Redis cache adapter (the connection is made by `RedisAdapter::createConnection()` from `symfony/cache`). Query parameters of the DSN, such as `?timeout=1`, override `timeout` and `read_timeout`.
- phpredis is used when the extension is loaded, Predis otherwise. Predis 1 needs the `sockets` PHP extension to disable Nagle's algorithm; without it each transaction below takes two round trips instead of one.
- The connection is opened with the first event, not when the container boots.

### Existing client

```yaml
three_brs_sentry_throttle:
    redis: app.redis # or { service: app.redis }
```

> [!WARNING]
> The timeouts of that client apply, `timeout` and `read_timeout` do not. When Redis does not answer, every request that sends an event waits as long as the client's timeouts allow. Use a client with short timeouts, or a DSN.

- The service must be a `\Redis` (phpredis), including the lazy connection of Symfony's cache, or a Predis client. A Predis 1 or 2 client should have `tcp_nodelay` enabled (Predis 1 applies it only with the `sockets` PHP extension); without it each transaction below takes two round trips instead of one.
- A phpredis client is shared: the throttle runs its transactions on it, and each of them also ends a `WATCH` that the application has open on it. While the application has a transaction (`multi()`) or a pipeline open on it, the throttle leaves it alone; that event counts only towards the per-process `fallback_max_per_window`. After a failed command the throttle closes the connection, so that no unread reply stays on it; phpredis reconnects with the next command.
- For a Predis client of a single Redis node, the throttle opens its own connection with the parameters and options of that client, without `persistent`, so that transactions and `WATCH` of the application are not affected. Other Predis clients, e.g. with replication or Sentinel, are used as they are; an event sent while the application has a transaction open on such a client makes that transaction fail.
- Redis Cluster, `RedisArray` (a DSN with several hosts) and Relay are not supported. With them the throttle fails open and logs it.

### Round trips

A sent event takes one round trip, a dropped event two. Each round trip is one `MULTI`/`EXEC` transaction in a pipeline:

1. The event is counted for its fingerprint and for the window total, and the number of the fingerprint's dropped events is taken; a sent event reports it in `throttle_occurrences`.
2. A dropped event puts that number back, plus one for itself, and takes itself off its fingerprint count and the window total, so that both count only sent events. Until then it counts towards both limits.

The first event in a process also opens the connection.

## How it works

### Fingerprint

- For an event with an exception: the innermost previous exception (the root cause) – its class, the `file:line` where it was thrown (relative to the project directory) and its message with numbers, hashes and UUIDs replaced by a placeholder.
- For other events: the level and the message, normalized in the same way.
- Only the first 8 KB of a message are used.

The fingerprint only decides what is counted together; it does not change how Sentry groups events.

### Windows and limits

- Windows are fixed periods of `window` seconds (by default 60).
- An event over the per-fingerprint limit is dropped. Otherwise it is checked against the global cap, and an event over the cap is dropped too. Both limits count the sent events of the window.
- Dropped events are counted per fingerprint. The next event of that fingerprint that is sent carries the occurrence tags.
- Redis keys start with `sentry_throttle:{prefix}:`. The counters of a window expire after two windows. The number of a fingerprint's dropped events expires 24 hours after the last drop, so drops older than that are not reported.

### Fail-open

- Any error inside the bundle: the event is sent.
- When Redis fails, the process does not use Redis until the current window ends and sends at most `fallback_max_per_window` events in that window. "Process" means one request in PHP-FPM and the whole process in a long-running worker. Events dropped by this cap are not counted in `throttle_occurrences`.
- The failure is logged as a warning, at most once per window and process. The warning contains the error message with the Redis password from the DSN replaced by `***`. With MonologBundle it goes to the `sentry_throttle` log channel; keep that channel away from Sentry:

```yaml
monolog:
    handlers:
        sentry:
            # ...
            channels: ['!sentry_throttle']
```

Both integrations drop events and records of this channel anyway, so that the warning cannot reach Sentry and start a loop.

### Environment variables

All options except `enabled`, the service id in `redis` and `monolog`, which decide how the services are wired, may come from environment variables, for example `%env(int:SENTRY_THROTTLE_PER_FINGERPRINT)%`.

- A `redis` value from an environment variable is always a DSN.
- The values are not validated when the container is compiled, but when they are used. With an empty `prefix`, or with `window` or a limit below 1, the throttle sends every event and logs a warning. A timeout that is not greater than 0, also one in the DSN, is handled as a Redis failure.
- A missing variable, or one that is not a number where a number is expected, makes Symfony fail when it creates the throttle, as with any service that uses environment variables. For the DSN and the timeouts that happens with the first event, which is then handled as a Redis failure.

## Configuration reference

```yaml
three_brs_sentry_throttle:

    # Decides which services are registered, so it cannot come from an environment variable; use when@dev / when@test.
    enabled:              true

    # Part of every Redis key, so that several applications can share one Redis.
    prefix:               null

    # Redis DSN, or the id of an existing Redis client service. A value from an environment variable or with a scheme (redis://...) is a DSN.
    redis:

        # Query parameters of the DSN override "timeout" and "read_timeout".
        dsn:                  null

        # The timeouts of this client apply, keep them short.
        service:              null

        # Connect timeout in seconds, greater than 0; DSN only.
        timeout:              0.2

        # Read timeout in seconds, greater than 0; DSN only.
        read_timeout:         0.2

    # For applications that send to Sentry through their own Monolog handler, without the Sentry SDK.
    monolog:

        # Ids of the Monolog handler services that send to Sentry; each of them is wrapped by the throttle.
        handlers:             []

        # Record field the wrapped handler reads Sentry tags from.
        tags:                 context # One of "context"; "extra"

    # Window length in seconds, at least 1.
    window:               60

    # Events with the same fingerprint sent per window, at least 1.
    per_fingerprint:      50

    # Events sent per window in total, at least 1; only events within the per-fingerprint limit count.
    max_per_window:       300

    # Events one process sends per window while Redis is unavailable, at least 1.
    fallback_max_per_window: 50
```

## Development

### Setting up Development Environment

1. Clone the repository:
```bash
git clone https://github.com/3BRS/sentry-throttle-bundle.git
cd sentry-throttle-bundle
```

2. Start the Docker environment (PHP and Redis) and install dependencies:
```bash
make up
make install
```

The PHP version defaults to 8.5. To work on another one, pass it to every command, for example `make up PHP_VERSION=8.1` and `make update-lowest PHP_VERSION=8.1` for the lowest supported dependencies.

### Available Make Commands

Run `make help` to see all available commands:

```bash
make install             # Install composer dependencies
make update              # Update composer dependencies
make update-lowest       # Update composer dependencies to the lowest supported versions
make test                # Run all checks (PHPUnit, ECS, PHPStan, Deptrac, Rector)
make phpunit             # Run PHPUnit tests
make phpunit-unit        # Run only unit tests
make phpunit-integration # Run only integration tests
make phpunit-coverage    # Run PHPUnit with HTML coverage report in var/coverage
make ecs                 # Check code style
make ecs-fix             # Fix code style
make phpstan             # Run PHPStan static analysis
make deptrac             # Run Deptrac architecture analysis
make rector              # Check code for automated refactoring opportunities
make rector-fix          # Apply automated refactorings
make audit               # Check dependencies for security vulnerabilities
make bash                # Connect to PHP container
```

## Testing

```bash
make test
```

- **Unit tests** cover the fingerprint, the throttle, the storage clients, the configuration and both integrations. Some of them talk to an unreachable address and to a local socket that never answers, to check that a dead Redis does not make requests wait longer than the timeouts.
- **Integration tests** use a real Redis (the `redis` service of `compose.yml`; set `SENTRY_THROTTLE_TEST_REDIS_DSN` to use another one; the tests change its `maxmemory` setting for a moment, so do not use a Redis that other applications use) and boot a Symfony kernel with the bundle, the Sentry SDK and Monolog.
- Tests of the optional Sentry and Monolog integrations are skipped when those packages are not installed.

### Architecture Layers

Deptrac keeps the core independent of the optional integrations: `Throttle`, `Fingerprint` and `Storage` must not depend on `Sentry` or `Monolog`.

## Continuous Integration

GitHub Actions runs these jobs, each with a Redis service:

| Job | PHP | Dependencies |
|-----|-----|--------------|
| `lowest` | 8.1 | `--prefer-lowest`: Symfony 5.4, Monolog 2.5, Sentry SDK 4.0 (without `sentry/sentry-symfony`, whose 5.x versions need SDK 4.6.1+), Predis 1.1 |
| `symfony-6-0` | 8.1 | `--prefer-lowest` with Symfony 6.0, Monolog 2.5 and Predis 2.0, including `sentry/sentry-symfony` 5.0 |
| `highest` | 8.5 | latest versions; also `composer validate`, ECS, PHPStan, Deptrac and Rector |
| `without-optional-packages` | 8.5 | latest versions without the Sentry and Monolog packages |

The jobs with `--prefer-lowest` set `COMPOSER_NO_BLOCKING=1` and `COMPOSER_NO_SECURITY_BLOCKING=1`: Composer 2.9+ leaves out versions with security advisories, which would raise the lowest versions. Composer 2.10+ reads `COMPOSER_NO_BLOCKING`, 2.9.2+ reads `COMPOSER_NO_SECURITY_BLOCKING`.

Static analysis runs in the `highest` job only: the Monolog compatibility trait is defined for the installed Monolog version, so it cannot be analysed for Monolog 2 and 3 at once.

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Run tests (`make test`)
5. Commit your changes (`git commit -m 'Add amazing feature'`)
6. Push to the branch (`git push origin feature/amazing-feature`)
7. Open a Pull Request

All contributions must pass `make test`: PHPUnit, ECS (PSR-12), PHPStan level 8, Deptrac and Rector.

## License

This project is licensed under the MIT License.

## Credits

Developed and maintained by [3BRS](https://3brs.com)

## Support

- [Report bugs](https://github.com/3BRS/sentry-throttle-bundle/issues)
- [Request features](https://github.com/3BRS/sentry-throttle-bundle/issues)
- [Sentry for Symfony](https://docs.sentry.io/platforms/php/guides/symfony/)
- [Filtering events in the Sentry SDK](https://docs.sentry.io/platforms/php/guides/symfony/configuration/filtering/)
