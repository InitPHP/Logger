# `FileLogger`

`InitPHP\Logger\FileLogger` appends each PSR-3 record as a single line to a
file on the local filesystem.

## Options

The constructor takes a single associative array. Only one key is recognised:

| Key | Type | Required | Description |
| --- | --- | --- | --- |
| `path` | `string` | yes | Target file path. May contain date tokens (see below). |

A missing, non-string, or empty `path` raises `\InvalidArgumentException`
synchronously during construction — failures are surfaced *before* you ever try
to log.

```php
use InitPHP\Logger\FileLogger;

$logger = new FileLogger([
    'path' => __DIR__ . '/logs/app.log',
]);
```

## Output format

Each call to `log()` writes exactly one line:

```
<ISO-8601 timestamp> [<UPPERCASE-LEVEL>] <interpolated message>\n
```

Example:

```
2026-05-24T14:08:22+03:00 [WARNING] cache miss for key user:42
```

The timestamp comes from `DateTimeImmutable('now')` formatted as `c`
(ISO-8601 with offset), and reflects the PHP-wide default timezone — set it
explicitly with `date_default_timezone_set()` if you need a specific one.

## Path tokens

The `path` value is interpolated **once**, at construction time, against the
process clock. Tokens follow the same `{name}` syntax used for PSR-3 context
placeholders:

| Token | Replacement |
| --- | --- |
| `{year}` | `date('Y')` (e.g. `2026`) |
| `{month}` | `date('m')` (e.g. `05`) |
| `{day}` | `date('d')` (e.g. `24`) |
| `{hour}` | `date('H')` (e.g. `14`) |
| `{minute}` | `date('i')` (e.g. `08`) |
| `{second}` | `date('s')` (e.g. `22`) |

```php
$logger = new FileLogger([
    'path' => __DIR__ . '/logs/app-{year}-{month}-{day}.log',
]);

echo $logger->getPath();
// /var/www/app/logs/app-2026-05-24.log
```

Because expansion happens **once**, a long-running PHP process started before
midnight will keep writing to the previous day's file. If you need true daily
rotation, see [`07-recipes.md`](07-recipes.md#daily-rotation).

## Directory creation

The parent directory of `path` is created automatically using
`mkdir($dir, 0775, true)` if it does not already exist. If creation fails — for
example because of restrictive filesystem permissions — the failure is reported
through `error_log()` and the subsequent write attempt proceeds normally
(producing its own `error_log()` notice on failure).

## Concurrency

Writes use `file_put_contents($path, $line, FILE_APPEND | LOCK_EX)`. The
`LOCK_EX` flag means concurrent PHP processes will not interleave the bytes of
a single line. It does **not** guarantee global ordering across processes; the
file's natural append-only semantics handle that.

## Error handling

PSR-3 §1.1 requires that log calls do not raise exceptions for ordinary
backend failures. `FileLogger` honours that rule:

- A write that returns `false` is reported via `error_log()` but does not
  throw.
- A directory creation failure is reported via `error_log()` but does not
  throw.
- Unknown log levels (passed to `log()` directly) **do** throw
  `Psr\Log\InvalidArgumentException`, as PSR-3 explicitly permits.

## Inspecting the resolved path

`getPath()` returns the final, token-interpolated path:

```php
$logger = new FileLogger(['path' => '/var/log/app-{year}.log']);
$logger->getPath();   // "/var/log/app-2026.log"
```

This is convenient in tests and in administrative tooling.

## Example: all eight PSR-3 levels

```php
use InitPHP\Logger\FileLogger;

$logger = new FileLogger(['path' => __DIR__ . '/logs/levels.log']);

$logger->emergency('system unusable');
$logger->alert    ('action required immediately');
$logger->critical ('critical condition');
$logger->error    ('runtime error, no immediate action required');
$logger->warning  ('exceptional condition, not an error');
$logger->notice   ('normal but significant event');
$logger->info     ('informational message');
$logger->debug    ('debug-level detail');
```
