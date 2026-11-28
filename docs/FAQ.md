# FAQ

Answers to questions that come up often. If your question is not here,
please [open a discussion](https://github.com/orgs/InitPHP/discussions) —
useful answers are folded back into this page.

## General

### Why another PSR-3 logger when Monolog exists?

Monolog is excellent and very full-featured. `initphp/logger` is
deliberately small: two concrete handlers, one fan-out multiplexer, no
optional dependencies. It is the right choice when:

- you only need file or database logging,
- you prefer reading the entire source of your dependencies before depending
  on them,
- you want a single-package install with no transitive surprises.

It is **not** the right choice when you need Monolog's handlers for
Elasticsearch, Sentry, Bugsnag, Slack via webhooks, etc. Because everything
is PSR-3, the two packages compose: you can wrap a `\Monolog\Logger` inside
an `InitPHP\Logger\Logger`, or vice versa.

### Is it production-ready?

Yes. It is small, tested ([`tests/`](https://github.com/InitPHP/Logger/tree/main/tests))
with PHPUnit, statically analysed at PHPStan level `max`, and CI-tested on
PHP 8.2 through 8.6. The handlers are the same kind of code countless
applications run in production already.

### What's the licence?

[MIT](https://github.com/InitPHP/Logger/blob/main/LICENSE).

## Compatibility

### Can I use it on PHP 7.4 (or 8.0 / 8.1)?

No. The package requires PHP 8.2 or later, and PSR-3 v3 requires PHP 8.0+ on its
own. Use 1.0.x of this package or another PSR-3 implementation if you are pinned
to PHP 7.x — but be aware that PHP 7.4 has been end-of-life since November 2022.
On PHP 8.0 or 8.1, the 2.0.x releases of this package are the ones to use.

### Will it work with PSR-3 v1 / v2 in my dependencies?

No — the package requires `psr/log ^3.0`. If you have a transitive
dependency that pins `psr/log` to v1, Composer will refuse to install
either upgrade the conflicting package or pin this one to an older release.

### Does it work with Symfony / Laravel?

Yes — both frameworks accept any `Psr\Log\LoggerInterface`. See
[Recipes › Symfony service container](Recipes.md#symfony-service-container) and
[Recipes › Laravel service container](Recipes.md#laravel-service-container) for
ready-to-paste configuration.

## Usage

### Which handler should I pick?

| If you want to… | Pick… |
| --- | --- |
| append to a file | [FileLogger](File-Logger.md) |
| insert into a relational table | [PDOLogger](PDO-Logger.md) |
| log somewhere else (syslog, Slack, in-memory…) | [Custom Handlers](Custom-Handlers.md) |
| do several of the above at once | wrap them in [Multi-Logger](Multi-Logger.md) |

### Can I log multiple things to multiple places?

Yes — that is exactly what `Logger` (the multiplexer) is for. See
[Multi-Logger](Multi-Logger.md).

### How do I rotate log files daily?

Two options: token-based paths for short-lived processes, or `logrotate`
for daemons. Full recipes in
[Recipes › Daily rotation](Recipes.md#daily-rotation).

### How do I log exceptions cleanly?

Pass the `\Throwable` as a context value and reference it with `{exception}`:

```php
try {
    $service->charge();
} catch (\Throwable $e) {
    $logger->error('payment failed: {exception}', ['exception' => $e]);
}
// → "payment failed: RuntimeException(0): gateway timed out in /app/src/Charge.php:88"
```

The package's `\Throwable` renderer is one line; if you want the stack
trace, pass it through a second placeholder:

```php
$logger->error("payment failed: {exception}\n{trace}", [
    'exception' => $e,
    'trace'     => $e->getTraceAsString(),
]);
```

See [Context Interpolation › Throwable values](Context-Interpolation.md#throwable-values).

### Can I filter by level?

The package itself does not ship a level filter, but a 30-line
`MinLevelLogger` decorator does it:
[Recipes › Logging only above a threshold](Recipes.md#logging-only-above-a-threshold).

### How do I attach a request id / user id to every log call?

Wrap the logger in a small decorator that merges fixed context into every
call. See
[Recipes › Attaching shared context](Recipes.md#attaching-shared-context-request-id-user-id).

## Behaviour

### Do log calls throw on failure?

PSR-3 §1.1 forbids it for ordinary backend failures, and the package
honours that for file I/O:

- **FileLogger** — disk errors → `error_log()` notice, no throw.
- **PDOLogger** — database errors → `\PDOException` **does** propagate.
  Wrap it in a `TolerantLogger` if you want it to be silent
  ([recipe](Recipes.md#database-logger-that-survives-outages)).
- **Unknown level** — `\Psr\Log\InvalidArgumentException` (explicitly
  permitted by PSR-3).
- **Bad configuration** — `\InvalidArgumentException` from the constructor
  (loud, immediate).

The full table is in [Error Handling](Error-Handling.md).

### Is log writing safe across concurrent processes?

`FileLogger` uses `FILE_APPEND | LOCK_EX` — concurrent writers cannot
interleave bytes within a single line. Cross-process ordering is the
file's natural append-only behaviour.

`PDOLogger` defers concurrency to the database. Inserts are atomic at the
SQL level; if you need strict global ordering, add an `id` column with
`AUTO_INCREMENT` / `BIGSERIAL` and order by it.

### What's the format of the timestamps?

- `FileLogger` writes timestamps in ISO-8601 with offset (`c` format), e.g.
  `2026-05-24T14:08:22+03:00`.
- `PDOLogger` writes timestamps as `Y-m-d H:i:s`, e.g.
  `2026-05-24 14:08:22`.

Both reflect the PHP-wide timezone. Set it with
`date_default_timezone_set()` if needed.

### Is the message length limited?

The package itself imposes no limit. Limits come from the storage backend:

- **FileLogger** — limited only by your filesystem and the
  `file_put_contents()` call (effectively several GB on Unix; do not log
  anything that big).
- **PDOLogger** — limited by your `message` column. `TEXT` is fine for
  typical messages; `MEDIUMTEXT` / `LONGTEXT` if you log full request
  bodies, stack traces, etc.

## Testing

### How do I write tests against logging behaviour?

Inject a different `LoggerInterface` in tests. Common patterns:

- **`Psr\Log\NullLogger`** — when you do not care.
- **Custom `ArrayLogger`** — when you assert on log content.
- **PHPUnit mock of `LoggerInterface`** — when you assert on a single call.

Full examples in [Testing Your Logging](Testing-Your-Logging.md).

### Can I capture log output during a functional test?

Yes — bind the test container's `LoggerInterface` to an `ArrayLogger`. See
[Testing › Capturing log output during functional tests](Testing-Your-Logging.md#capturing-log-output-during-functional-tests).

## Contributing

### How do I report a bug?

Open an issue on
[github.com/InitPHP/Logger/issues](https://github.com/InitPHP/Logger/issues).
Include the PHP version, the package version (`composer show initphp/logger`),
and a minimal reproducer.

### How do I propose a new feature?

Open a [Discussion](https://github.com/orgs/InitPHP/discussions/categories/ideas)
first. Larger PRs without prior discussion run the risk of being too far
out of scope to merge.

### How do I report a security vulnerability?

**Not** as a public issue. Follow the steps in the org-wide
[`SECURITY.md`](https://github.com/InitPHP/.github/blob/main/SECURITY.md)
— either GitHub Private Vulnerability Reporting on the package repo, or
email to [info@muhammetsafak.com](mailto:info@muhammetsafak.com) with
`SECURITY` in the subject line.
