# Changelog

Notable changes to `3brs/sentry-throttle-bundle`. Follows
[Keep a Changelog](https://keepachangelog.com/) and [SemVer](https://semver.org/).

## [1.0.0] - 2026-10-27

### Added
- **Supported versions:** PHP 8.1+ and Symfony 5.4, 6.x, 7.x or 8.x; the Sentry SDK integration
  needs `sentry/sentry` 4.x, the Monolog integration Monolog 2.5+ or 3.
- **Per-fingerprint limit and global cap.** At most `per_fingerprint` events with the same
  fingerprint (default 50) and at most `max_per_window` events in total (default 300) are sent per
  `window` (default 60 seconds); only events within the per-fingerprint limit count towards the cap.
  The counters live in Redis under `sentry_throttle:{prefix}:`, so all processes of an application
  share them and several applications can share one Redis.
- **Occurrence tags.** The first event of a fingerprint sent after drops carries `throttled: true`
  and `throttle_occurrences`, the number of its events dropped since the previous sent one.
- **Fingerprint** from the root-cause exception — its class, the place where it was thrown relative
  to the project directory and its message with numbers, hashes and UUIDs replaced — or, for events
  without an exception, from the level and the message.
- **Redis storage for phpredis and Predis 1.1.10+, 2 and 3.** `redis` takes either a DSN, from
  which the bundle opens its own connection with the first event and with 0.2 s connect and read
  timeouts, or the id of an existing client service. A sent event takes one round trip, a dropped
  event two. A shared phpredis client, also Symfony's lazy one, is left alone while the application
  has a transaction or a pipeline open on it; for a Predis client of a single Redis node the
  throttle opens its own connection with the client's parameters and options.
- **Fail-open.** When Redis fails, or anything inside the bundle does, the event is sent and nothing
  is thrown. Until the window ends the process skips Redis and sends at most
  `fallback_max_per_window` events (default 50), and it logs one warning to the `sentry_throttle`
  channel, which both integrations keep away from Sentry. With options from environment variables
  out of range, the throttle sends every event and logs one warning.
- **`ThrottleBeforeSend`** — the `before_send` callback for the Sentry SDK 4.x, which an application
  with its own `before_send` calls as the last step.
- **Monolog handler wrapper** for Monolog 2.5+ and 3: `monolog.handlers` lists the handlers that
  send to Sentry, `monolog.tags` the record field (`context` or `extra`) they read Sentry tags from.
- **`enabled`** switches the throttle off per environment; both integrations then pass every event
  through.

[1.0.0]: https://github.com/3BRS/sentry-throttle-bundle/releases/tag/v1.0.0
