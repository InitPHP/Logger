# API Reference

Authoritative reference for every public class member shipped by
`initphp/logger`. Method signatures are reproduced verbatim from the
source; behavioural details correspond to the current `main` branch.

| Class | Namespace | Extends / Implements |
| --- | --- | --- |
| [Logger](#logger) | `InitPHP\Logger\Logger` | `Psr\Log\AbstractLogger` (final) |
| [FileLogger](#filelogger) | `InitPHP\Logger\FileLogger` | `Psr\Log\AbstractLogger` |
| [PDOLogger](#pdologger) | `InitPHP\Logger\PDOLogger` | `Psr\Log\AbstractLogger` |
| [HelperTrait](#helpertrait) | `InitPHP\Logger\HelperTrait` | trait (`@internal`) |

All three concrete classes implement `Psr\Log\LoggerInterface` transitively
through `AbstractLogger`.

---

## `Logger`

```php
final class InitPHP\Logger\Logger extends \Psr\Log\AbstractLogger
```

Fan-out multiplexer. Forwards every PSR-3 call to every registered inner
logger, in registration order. See [Multi-Logger](Multi-Logger.md) for the
narrative documentation.

### `__construct`

```php
public function __construct(\Psr\Log\LoggerInterface ...$loggers)
```

**Parameters**

| Name | Type | Description |
| --- | --- | --- |
| `$loggers` | variadic `LoggerInterface` | One or more PSR-3 loggers to dispatch to. |

**Throws**

- `\InvalidArgumentException` — when zero loggers are supplied.
- `\TypeError` — when any argument is not a `Psr\Log\LoggerInterface`
  (raised by PHP at the call site).

**Example**

```php
new Logger(
    new FileLogger(['path' => '/var/log/app.log']),
    new PDOLogger(['pdo' => $pdo, 'table' => 'logs']),
);
```

### `log`

```php
public function log($level, string|\Stringable $message, array $context = []): void
```

Iterates the registered loggers and calls `$inner->log($level, $message, $context)`
on each, in order. Exceptions raised by inner loggers are **not** caught and
abort the rest of the fan-out.

**Parameters**

| Name | Type | Description |
| --- | --- | --- |
| `$level` | `mixed` (typically a `Psr\Log\LogLevel` constant) | One of the eight PSR-3 levels. |
| `$message` | `string\|\Stringable` | Log message, optionally containing `{placeholder}` tokens. |
| `$context` | `array<string, mixed>` | Placeholder values. See [Context Interpolation](Context-Interpolation.md). |

**Throws**

- `\Psr\Log\InvalidArgumentException` — when an inner handler raises it for
  an unknown level.
- Any exception thrown by an inner handler (e.g. `\PDOException`).

### `getLoggers`

```php
public function getLoggers(): array
```

Returns the inner loggers in the order they were registered.

**Returns** `list<\Psr\Log\LoggerInterface>`

### Inherited helpers

From `\Psr\Log\AbstractLogger`, all eight delegate to `log()`:

```php
public function emergency(string|\Stringable $message, array $context = []): void;
public function alert    (string|\Stringable $message, array $context = []): void;
public function critical (string|\Stringable $message, array $context = []): void;
public function error    (string|\Stringable $message, array $context = []): void;
public function warning  (string|\Stringable $message, array $context = []): void;
public function notice   (string|\Stringable $message, array $context = []): void;
public function info     (string|\Stringable $message, array $context = []): void;
public function debug    (string|\Stringable $message, array $context = []): void;
```

---

## `FileLogger`

```php
class InitPHP\Logger\FileLogger extends \Psr\Log\AbstractLogger
{
    use \InitPHP\Logger\HelperTrait;
}
```

Appends each record as a single line to a file. See
[FileLogger](File-Logger.md) for the narrative documentation.

### `__construct`

```php
public function __construct(array $options = [])
```

**Parameters**

| Name | Type | Description |
| --- | --- | --- |
| `$options` | `array{path?: string}` | Configuration array. Only the `path` key is read. |

**Recognised options**

| Key | Type | Required | Description |
| --- | --- | --- | --- |
| `path` | `string` | yes | Destination file path. May contain `{year}`, `{month}`, `{day}`, `{hour}`, `{minute}`, `{second}` tokens. |

**Throws**

- `\InvalidArgumentException` — when `path` is missing, not a string, or empty.

**Example**

```php
new FileLogger(['path' => __DIR__ . '/logs/app-{year}-{month}-{day}.log']);
```

### `log`

```php
public function log($level, string|\Stringable $message, array $context = []): void
```

Writes exactly one line of the form:

```text
<ISO-8601 timestamp> [<UPPERCASE-LEVEL>] <interpolated message>\n
```

**Parameters**

| Name | Type | Description |
| --- | --- | --- |
| `$level` | `mixed` (typically a `Psr\Log\LogLevel` constant) | One of the eight PSR-3 levels. |
| `$message` | `string\|\Stringable` | Log message with optional placeholders. |
| `$context` | `array<string, mixed>` | Placeholder values. |

**Throws**

- `\Psr\Log\InvalidArgumentException` — when `$level` is not a recognised
  PSR-3 level.

**Failure modes (silent)**

- Failure to create the parent directory → `error_log()` notice, no exception.
- Failure of `file_put_contents()` → `error_log()` notice, no exception.

Concurrency: writes use `FILE_APPEND | LOCK_EX`.

### `getPath`

```php
public function getPath(): string
```

Returns the destination file path **after** token interpolation.

**Returns** `string`

**Example**

```php
$logger = new FileLogger(['path' => '/var/log/app-{year}.log']);
$logger->getPath();   // "/var/log/app-2026.log"
```

### Properties

| Visibility | Name | Type | Description |
| --- | --- | --- | --- |
| `protected` | `$path` | `string` | Token-resolved destination path. |

---

## `PDOLogger`

```php
class InitPHP\Logger\PDOLogger extends \Psr\Log\AbstractLogger
{
    use \InitPHP\Logger\HelperTrait;
}
```

Inserts each record as a row in a relational table. See
[PDOLogger](PDO-Logger.md) for the narrative documentation.

### `__construct`

```php
public function __construct(array $options = [])
```

**Parameters**

| Name | Type | Description |
| --- | --- | --- |
| `$options` | `array{pdo?: \PDO, table?: string}` | Configuration array. |

**Recognised options**

| Key | Type | Required | Description |
| --- | --- | --- | --- |
| `pdo` | `\PDO` | yes | Configured PDO connection. |
| `table` | `string` | yes | Destination table name. Must match `/^[A-Za-z_][A-Za-z0-9_]*$/`. |

**Throws** (all `\InvalidArgumentException`)

- `pdo` key missing
- `pdo` not a `PDO` instance
- `table` key missing
- `table` empty / not a string
- `table` fails the identifier regex

The exact messages are listed in
[PDOLogger › Validation](PDO-Logger.md#validation).

### `log`

```php
public function log($level, string|\Stringable $message, array $context = []): void
```

Inserts one row:

```sql
INSERT INTO <table> (level, message, date) VALUES (?, ?, ?)
```

with `level` uppercased, `message` interpolated, `date` formatted as
`Y-m-d H:i:s` at insertion time. Bound through prepared statements.

**Throws**

- `\Psr\Log\InvalidArgumentException` — unknown level.
- `\PDOException` — any database failure (connection lost, table missing,
  permission denied, …). Not swallowed; see
  [Error Handling › Database failures](Error-Handling.md#database-failures-in-pdologger).

### `getTable`

```php
public function getTable(): string
```

Returns the configured destination table name.

**Returns** `string`

### Properties

| Visibility | Name | Type | Description |
| --- | --- | --- | --- |
| `protected` | `$pdo` | `\PDO` | The supplied PDO connection. |
| `protected` | `$table` | `string` | Validated table name. |

### Class constants

| Visibility | Name | Type | Value |
| --- | --- | --- | --- |
| `private` | `TABLE_NAME_PATTERN` | `string` | `/^[A-Za-z_][A-Za-z0-9_]*$/` |

---

## `HelperTrait`

```php
trait InitPHP\Logger\HelperTrait
```

> **Note.** Marked `@internal`. The trait is part of the implementation
> surface of the bundled handlers and may shift between patch releases.
> Reusing it in your own handlers is supported but at your own risk —
> there is no compatibility guarantee.

### `interpolate`

```php
protected function interpolate(string|\Stringable $message, array $context = []): string
```

Expands `{placeholder}` tokens. Rendering rules:

| Value type | Rendered as |
| --- | --- |
| `null` | `""` |
| `true`, `false` | `"true"`, `"false"` |
| `int`, `float`, `string` | `(string) $value` |
| `\Stringable` | `(string) $value` |
| `\Throwable` | `"<Class>(<code>): <message> in <file>:<line>"` |
| arrays | placeholder left untouched |
| non-stringable objects | placeholder left untouched |

Non-string context keys are skipped. See
[Context Interpolation](Context-Interpolation.md) for examples.

**Returns** `string`

### `getDate`

```php
protected function getDate(string $format = 'c'): string
```

Returns the current time formatted with `DateTimeImmutable::format()`.
Defaults to ISO-8601 with offset (`'c'`).

**Returns** `string`

### `logLevelVerify`

```php
protected function logLevelVerify(mixed $level): void
```

Validates that `$level` is one of the eight PSR-3 level strings, comparing
case-insensitively.

**Throws** `\Psr\Log\InvalidArgumentException` on failure.

**PHPStan note.** The trait carries a `@phpstan-assert string $level`
annotation, so after calling `logLevelVerify($level)` static analysers
know `$level` is a string.

### Internal properties

| Visibility | Name | Type | Description |
| --- | --- | --- | --- |
| `private` | `$levels` | `list<string>` | The eight canonical PSR-3 level strings, in severity order. |

---

## Exception types at a glance

| Where? | Exception | When? |
| --- | --- | --- |
| `Logger::__construct` | `\InvalidArgumentException` | Zero loggers. |
| `Logger::__construct` | `\TypeError` | Non-`LoggerInterface` argument (PHP-enforced). |
| `FileLogger::__construct` | `\InvalidArgumentException` | `path` missing / empty / non-string. |
| `PDOLogger::__construct` | `\InvalidArgumentException` | `pdo` or `table` missing/wrong/invalid. |
| `*Logger::log()` | `\Psr\Log\InvalidArgumentException` | Unknown level. |
| `PDOLogger::log()` | `\PDOException` | Backend failure (propagated). |
| `Logger::log()` | (inner exception verbatim) | Inner handler raised. |

## Related

- Narrative docs: [Multi-Logger](Multi-Logger.md),
  [FileLogger](File-Logger.md), [PDOLogger](PDO-Logger.md).
- [PSR-3 Compliance](PSR-3-Compliance.md) and
  [Error Handling](Error-Handling.md) for behavioural details.
- [Custom Handlers › Guidelines](Custom-Handlers.md#guidelines-for-handler-authors)
  for writing handlers that match the conventions above.
