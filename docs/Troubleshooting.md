# Troubleshooting

Diagnosing common failure modes, in roughly the order they show up in real
projects.

## "I called `info()` but nothing happens"

Most common cause: `FileLogger` is writing to a path you cannot read, or
the write failed silently.

### 1. Confirm the resolved path

`getPath()` returns the final, token-interpolated path. Check it matches
where you are looking:

```php
$logger = new FileLogger(['path' => '/var/log/app-{year}.log']);
echo $logger->getPath();   // is this where you tail-ed?
```

### 2. Check the SAPI error log

`FileLogger` reports write/`mkdir` failures via `error_log()`. Where this
ends up depends on your environment:

| Environment | Default `error_log` destination |
| --- | --- |
| Apache + mod_php / PHP-FPM | the web server's `error.log` |
| CLI | `stderr` |
| Custom `error_log` ini setting | wherever it points |

Look for messages like:

```text
InitPHP\Logger\FileLogger: failed to write log entry to "/var/log/app.log".
InitPHP\Logger\FileLogger: failed to create directory "/var/log/app".
```

If they are there, you have a permissions or disk-space issue.

### 3. Check the permissions

```bash
ls -ld /var/log/app
ls -l /var/log/app/app.log
```

PHP-FPM typically runs as `www-data` (Ubuntu) or `nginx` (RHEL/Alpine). The
process must be able to:

- write into the parent directory if the file does not yet exist,
- write into the file if it does.

The package creates parent directories with mode `0775`; if your umask /
group setup is restrictive, you may need to pre-create them with the right
owner.

### 4. Check the timezone

`FileLogger` uses `date('Y'/'m'/'d'/...)` for path token expansion. If your
PHP timezone is wrong, you may be looking at yesterday's file:

```bash
php -r 'echo date_default_timezone_get();'
```

Set it explicitly with `date_default_timezone_set()` at boot.

## "I get `InvalidArgumentException: FileLogger requires a non-empty string \"path\" option.`"

Self-explanatory: the `path` key is missing, not a string, or empty.

```php
new FileLogger([]);                            // ✗
new FileLogger(['path' => null]);              // ✗
new FileLogger(['path' => '']);                // ✗
new FileLogger(['path' => 123]);               // ✗
new FileLogger(['path' => '/var/log/app.log']); // ✓
```

If you load `path` from configuration, log the resolved value before
constructing the logger — typos in `.env` files are a common cause.

## "I get `InvalidArgumentException: PDOLogger \"table\" option \"X\" is not a valid SQL identifier`"

`PDOLogger` requires table names to match `/^[A-Za-z_][A-Za-z0-9_]*$/`.
Triggered by:

| Table name | Reason |
| --- | --- |
| `'log records'` | space |
| `'123logs'` | starts with digit |
| `'logs-2026'` | hyphen |
| `'public.logs'` | dot (use `'logs'` and select the right schema in your PDO connection instead) |
| `` '`logs`' `` | backticks |

Pick a name that matches the pattern; see
[PDOLogger › Why the table-name regex?](PDO-Logger.md#why-the-table-name-regex)
for the rationale.

## "I get `\Psr\Log\InvalidArgumentException: Unknown log level`"

You passed an unrecognised string to `log()`. The eight allowed values are
the lowercase constants in `\Psr\Log\LogLevel`:

```text
emergency, alert, critical, error, warning, notice, info, debug
```

Common slips:

- `$logger->log('warn', ...)` — should be `'warning'`.
- `$logger->log('fatal', ...)` — should be `'critical'` or `'emergency'`.
- `$logger->log('trace', ...)` — there is no trace level in PSR-3; use
  `'debug'`.

If your application has its own internal level enum, map it to PSR-3 once
in a small helper instead of repeating the conversion at every call site.

## "PDO calls throw — the database goes down and my whole app stops logging"

By design — `PDOLogger` lets `\PDOException` propagate so outages are
visible.

If a database outage must not interrupt application flow, wrap `PDOLogger`
in a `TolerantLogger` decorator. The full pattern is in
[Recipes › Database logger that survives outages](Recipes.md#database-logger-that-survives-outages).

## "Two processes are interleaving lines in my log file"

That should not happen — `FileLogger` writes with `LOCK_EX`. If it does,
suspect one of:

- A second writer not using `LOCK_EX` (raw `fwrite`, `tail | sed >>`,
  `logrotate copytruncate` mid-write).
- A network filesystem (NFS, SMB) that does not honour POSIX advisory locks.
- Your editor's "save in place" rewriting the file while a writer holds the
  lock.

For NFS/SMB shares, prefer a dedicated log shipper (Vector, Filebeat,
Promtail) over direct file appends from multiple hosts.

## "The first log line has the wrong date"

If you constructed the logger long before the first log call (e.g. at
container build time, hours before the first request), only the **path
tokens** are stale — the date inside each log line is computed on every
call.

```php
$logger = new FileLogger(['path' => '/var/log/app-{year}-{month}-{day}.log']);
// At 23:59:59 on May 24 — path is "...-2026-05-24.log"
sleep(2);
$logger->info('post-midnight');
// Line content: "2026-05-25T00:00:01+03:00 [INFO] post-midnight"
// File:         "...-2026-05-24.log"  (still the construction date!)
```

This is path-token expansion behaviour; see
[FileLogger › Path tokens](File-Logger.md#path-tokens). For accurate daily
rotation in long-running processes, see
[Recipes › Daily rotation › Long-lived processes](Recipes.md#long-lived-processes--workers-daemons).

## "Memory keeps growing in long-running workers"

The package itself does not retain references between calls — every log
call is fully processed and discarded. If memory grows:

- Suspect a **`WithContext`-style wrapper** that accumulates entries across
  requests (a common mistake when ports a per-request decorator to a
  long-running worker).
- Suspect **`PDO`'s emulated statement cache** if you have many distinct
  prepared statements. Setting `PDO::ATTR_EMULATE_PREPARES => false` shifts
  caching to the server.
- Confirm with `memory_get_usage(true)` snapshots that the growth is
  actually attributable to the logger and not to your business logic.

## "Logs end up with literal `{user}` instead of the value"

The context array key did not match. Things to check:

- The key is a **string**, not an integer.
- The key matches exactly (`{user}` ↔ `'user' => 'jane'`).
- The value is not an array or a non-stringable object — those are skipped
  by design ([Context Interpolation › How context values are rendered](Context-Interpolation.md#how-context-values-are-rendered)).

If you want array/object values rendered, encode them explicitly:

```php
$logger->info('payload: {body}', ['body' => json_encode($data)]);
```

## "Composer refuses to install the package"

The most common cause is a `psr/log` version conflict. Find out who pins it:

```bash
composer why psr/log
```

If a dependency requires `psr/log` v1 or v2 and refuses to update, you have
three options:

1. Update the conflicting package to a version that supports `psr/log ^3.0`.
2. Stay on 1.0.x of `initphp/logger`.
3. Replace the conflicting dependency.

## Getting more help

If none of the above matches:

- **Bug** → [GitHub issue](https://github.com/InitPHP/Logger/issues/new),
  with PHP version, package version, full error message, and a minimal
  reproducer.
- **Question** → [GitHub Discussions › Q&A](https://github.com/orgs/InitPHP/discussions/categories/q-a).
- **Security vulnerability** → never publicly; follow
  [`SECURITY.md`](https://github.com/InitPHP/.github/blob/main/SECURITY.md).
