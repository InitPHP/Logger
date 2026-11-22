# FileLogger

`InitPHP\Logger\FileLogger` appends each PSR-3 record as a single line to a
file on the local filesystem.

```php
use InitPHP\Logger\FileLogger;

$logger = new FileLogger(['path' => __DIR__ . '/logs/app.log']);
$logger->info('hello');
// logs/app.log → "2026-05-24T14:08:22+03:00 [INFO] hello\n"
```

## Constructor

```php
public function __construct(array $options = [])
```

The constructor accepts a single associative array. Only one key is recognised:

| Option | Type | Required | Description |
| --- | --- | --- | --- |
| `path` | `string` | **yes** | Destination file path. May contain date tokens — see below. |

Anything else in the `$options` array is ignored.

### Validation

The constructor raises `\InvalidArgumentException` synchronously if `path` is
missing, not a string, or empty:

```text
FileLogger requires a non-empty string "path" option.
```

This means a misconfigured logger fails at boot, not on the first log call.
Catching the exception is rarely useful — fix the configuration instead.

## Output format

Every call to a PSR-3 method writes **exactly one line** of the shape:

```text
<ISO-8601 timestamp> [<UPPERCASE-LEVEL>] <interpolated message>\n
```

Concrete example:

```text
2026-05-24T14:08:22+03:00 [WARNING] cache miss for key user:42
```

- **Timestamp** — `DateTimeImmutable('now')` formatted with `c` (ISO-8601 with
  offset). The PHP-wide default timezone is used; set it with
  `date_default_timezone_set()` if you need a specific zone.
- **Level** — the PSR-3 level uppercased verbatim. Inputs are matched
  case-insensitively against the eight PSR-3 constants, but the file always
  shows them uppercase for grep-friendliness.
- **Message** — the user's message with all `{placeholder}` tokens expanded
  from the `$context` array; see [Context Interpolation](Context-Interpolation.md).
- **Newline** — `PHP_EOL` (a single byte on Unix, two on Windows). The line
  terminator is **appended**, not prepended, so files start with a real log
  entry on line 1.

## Path tokens

The `path` value is interpolated **once**, at construction time, against the
process clock. Six tokens are recognised, using the same `{name}` syntax as
the PSR-3 context placeholders:

| Token | Replacement (PHP function) |
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

Because tokens are expanded **only once**, a long-running PHP process started
before midnight keeps writing to the previous day's file until it restarts.
For true daily rotation, see [Recipes › Daily rotation](Recipes.md#daily-rotation).

## Automatic directory creation

The parent directory of `path` is created automatically with
`mkdir($dir, 0775, true)` when it does not already exist. The recursive flag
means you can nest as deeply as you like:

```php
$logger = new FileLogger([
    'path' => __DIR__ . '/logs/{year}/{month}/app.log',
]);
$logger->info('hi');
// logs/2026/05/app.log
```

If `mkdir()` fails (e.g. parent directory not writable), the failure is
reported through `error_log()` and the subsequent `file_put_contents()`
attempt proceeds normally — producing its own `error_log()` notice on failure.
**No exception** is thrown; PSR-3 method calls must not interrupt application
flow.

## Concurrency

Writes use:

```php
file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
```

- `FILE_APPEND` — atomically appends without truncating.
- `LOCK_EX` — acquires an advisory exclusive lock for the duration of the
  write. Concurrent PHP processes will not interleave the bytes of a single
  log line.

`LOCK_EX` does **not** guarantee ordering between processes; the file's
natural append-only semantics handle that. Two simultaneous writers may end
up with their lines in either order, but each line itself remains intact.

## Error handling

PSR-3 §1.1 mandates that ordinary backend failures must **not** cause `log()`
calls to throw. `FileLogger` honours that rule:

| Condition | Behaviour |
| --- | --- |
| `file_put_contents` returns `false` | `error_log()` notice, no exception. |
| Directory creation fails | `error_log()` notice, write attempt continues. |
| Unknown level passed to `log()` | `\Psr\Log\InvalidArgumentException` (permitted by PSR-3). |
| `path` missing/invalid at construction | `\InvalidArgumentException` (configuration error, not logging). |

See [Error Handling](Error-Handling.md) for the package-wide policy.

## Inspecting the resolved path

`getPath()` returns the path **after** token interpolation. Convenient in
tests, in administrative tooling, or in a `--diagnose` CLI flag:

```php
$logger = new FileLogger(['path' => '/var/log/app-{year}.log']);
echo $logger->getPath();
// "/var/log/app-2026.log"
```

## Complete example: all eight PSR-3 levels

```php
<?php
require __DIR__ . '/vendor/autoload.php';

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

Resulting `levels.log`:

```text
2026-05-24T14:08:22+03:00 [EMERGENCY] system unusable
2026-05-24T14:08:22+03:00 [ALERT] action required immediately
2026-05-24T14:08:22+03:00 [CRITICAL] critical condition
2026-05-24T14:08:22+03:00 [ERROR] runtime error, no immediate action required
2026-05-24T14:08:22+03:00 [WARNING] exceptional condition, not an error
2026-05-24T14:08:22+03:00 [NOTICE] normal but significant event
2026-05-24T14:08:22+03:00 [INFO] informational message
2026-05-24T14:08:22+03:00 [DEBUG] debug-level detail
```

## Related

- [Multi-Logger](Multi-Logger.md) — wrap `FileLogger` together with other handlers.
- [Context Interpolation](Context-Interpolation.md) — placeholder rules and
  `{exception}` rendering.
- [Recipes › Daily rotation](Recipes.md#daily-rotation),
  [Recipes › Two files: everything + errors-only](Recipes.md#two-files-everything--errors-only).
- [API Reference › FileLogger](API-Reference.md#filelogger).
