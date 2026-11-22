# Error Handling

PSR-3 splits errors into two categories: configuration mistakes (which should
fail loudly) and runtime backend failures (which must not interrupt the host
application). The package follows that split.

## Quick decision table

| What happens? | When? | Result |
| --- | --- | --- |
| `\InvalidArgumentException` | Bad option at construction (`path`, `pdo`, `table`) | Throws (from constructor) |
| `\Psr\Log\InvalidArgumentException` | Unknown log level at `log()` time | Throws (from `log()`) |
| `\TypeError` | Wrong argument type at PHP call boundary (e.g. non-`LoggerInterface` to `Logger`) | Throws (PHP-level) |
| Silent + `error_log()` notice | `FileLogger` write failure, directory creation failure | Does **not** throw |
| Bubbled-up `\PDOException` | Database outage during `PDOLogger::log()` | Throws (raw PDO exception) |
| Bubbled-up exception from inner handler | Inner handler in `Logger` raises | Throws (re-thrown verbatim) |

## Construction-time errors

Misconfiguration is a programming mistake — it should fail fast, with a
clear message, at boot, not silently corrupt log output.

```php
new FileLogger([]);
// ✗ InvalidArgumentException: FileLogger requires a non-empty string "path" option.

new PDOLogger(['pdo' => 'not-a-pdo', 'table' => 'logs']);
// ✗ InvalidArgumentException: PDOLogger "pdo" option must be a PDO instance.

new PDOLogger(['pdo' => $pdo, 'table' => 'log records']);
// ✗ InvalidArgumentException: PDOLogger "table" option "log records" is not a valid SQL identifier; ...

new Logger();
// ✗ InvalidArgumentException: InitPHP\Logger\Logger requires at least one Psr\Log\LoggerInterface instance.

new Logger('not-a-logger');
// ✗ TypeError: ... must be of type Psr\Log\LoggerInterface, string given
```

All five examples raise during construction, before any log call is made.
Catching them is rarely useful — fix the configuration instead.

## Log-time errors per PSR-3

PSR-3 §1.1 permits exactly one error type from `log()`:
`\Psr\Log\InvalidArgumentException`, raised when the level argument is not
one of the eight defined levels.

```php
$logger->log('verbose', 'oops');
// ✗ \Psr\Log\InvalidArgumentException: Unknown log level "verbose". Allowed PSR-3 levels are: emergency, alert, critical, error, warning, notice, info, debug.
```

The exception class is in the `Psr\Log\` namespace — **not** the SPL
`\InvalidArgumentException`. This matters when you want to catch it:

```php
try {
    $logger->log($userInput, 'oops');
} catch (\Psr\Log\InvalidArgumentException $e) {
    // user-driven level mistake
}
```

Note that the eight named helpers (`emergency()`, …, `debug()`) cannot
produce this error — they bake the level in.

## Filesystem failures in `FileLogger`

PSR-3 §1.1 forbids the logger from interrupting application flow on backend
failure. `FileLogger` follows that rule:

| Condition | Behaviour |
| --- | --- |
| `file_put_contents()` returns `false` | An `error_log()` notice is emitted, no exception thrown. |
| Parent directory creation fails (`mkdir()` returns `false` and the directory does not exist afterwards) | An `error_log()` notice is emitted, the write proceeds and may itself fail with another notice. |
| Disk full, permissions denied, network filesystem stalls | Same as above — `error_log()` notice, no exception. |

Where `error_log()` notices end up depends on your PHP configuration:
typically the SAPI error log (`error.log` for Apache/PHP-FPM) or `stderr`
for CLI. They look like:

```text
InitPHP\Logger\FileLogger: failed to write log entry to "/var/log/app.log".
InitPHP\Logger\FileLogger: failed to create directory "/var/log/app".
```

If write failures must be visible to your application, wrap the logger:
see [Recipes › Database logger that survives outages](Recipes.md#database-logger-that-survives-outages)
— the exact same decorator pattern works for any handler.

## Database failures in `PDOLogger`

`PDOLogger` does **not** swallow PDO exceptions. If the database is down,
the call raises:

```php
$logger->error('boom');
// → \PDOException: SQLSTATE[HY000] [2002] Connection refused
```

The reasoning is twofold:

1. Letting database errors propagate makes outages visible immediately,
   rather than producing silently-empty log tables that obscure incident
   response.
2. Wrapping in a fault-tolerant decorator is a deliberate, **opt-in**
   choice — see the recipe below.

### Fault-tolerant wrapper

If a database outage must not abort the rest of the application or the rest
of the fan-out chain, decorate `PDOLogger`:

```php
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class TolerantLogger extends AbstractLogger
{
    public function __construct(private LoggerInterface $inner) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        try {
            $this->inner->log($level, $message, $context);
        } catch (\Throwable $e) {
            error_log(sprintf(
                'TolerantLogger: %s (level=%s, msg=%s)',
                $e->getMessage(),
                (string) $level,
                (string) $message
            ));
        }
    }
}

$logger = new InitPHP\Logger\Logger(
    new InitPHP\Logger\FileLogger(['path' => '/var/log/app.log']),
    new TolerantLogger(new InitPHP\Logger\PDOLogger([
        'pdo'   => $pdo,
        'table' => 'logs',
    ]))
);
```

Now the file write is unaffected by database problems, but operators still
see PDO failures in the SAPI error log via `error_log()`.

## Exception propagation through `Logger`

`Logger::log()` iterates inner handlers and **does not catch** their
exceptions. The first inner handler that throws aborts the rest of the
fan-out:

```php
$logger = new Logger($a, $b, $c);
$logger->error('boom');
// If $b throws, $c never sees the call.
```

This is intentional. See [Multi-Logger › Exception propagation](Multi-Logger.md#exception-propagation)
for the full reasoning and for the "decorate the flaky one" pattern.

## Summary

The package adheres to the principle **"loud configuration, quiet operation"**:

- Bad configuration → loud, immediate exception with a specific message.
- Bad runtime input (unknown level) → loud, PSR-3-defined exception.
- Backend failure (disk, network) → quiet, `error_log()` notice; the
  application keeps running.
- Database backend failure → still loud (a `PDOException`), unless you
  deliberately wrap the handler.

## Related

- [PSR-3 Compliance](PSR-3-Compliance.md) — the rules the package implements.
- [Multi-Logger › Exception propagation](Multi-Logger.md#exception-propagation).
- [Recipes › Database logger that survives outages](Recipes.md#database-logger-that-survives-outages).
- [Troubleshooting](Troubleshooting.md) — diagnosing specific failure modes.
