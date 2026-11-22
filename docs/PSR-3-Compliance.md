# PSR-3 Compliance

The package targets [PSR-3 v3](https://www.php-fig.org/psr/psr-3/), the
current version of the PHP-FIG logging interface. It declares the
implementation through Composer's `provide` mechanism:

```json
"provide": {
    "psr/log-implementation": "3.0"
}
```

This page documents how each rule of the specification maps to the package's
behaviour.

## The contract

PSR-3 defines a single interface, `Psr\Log\LoggerInterface`, with nine
methods:

```php
namespace Psr\Log;

interface LoggerInterface
{
    public function emergency(string|\Stringable $message, array $context = []): void;
    public function alert    (string|\Stringable $message, array $context = []): void;
    public function critical (string|\Stringable $message, array $context = []): void;
    public function error    (string|\Stringable $message, array $context = []): void;
    public function warning  (string|\Stringable $message, array $context = []): void;
    public function notice   (string|\Stringable $message, array $context = []): void;
    public function info     (string|\Stringable $message, array $context = []): void;
    public function debug    (string|\Stringable $message, array $context = []): void;
    public function log      ($level, string|\Stringable $message, array $context = []): void;
}
```

All three handlers shipped in this package implement that interface:

- [`Logger`](Multi-Logger.md) — extends `\Psr\Log\AbstractLogger`.
- [`FileLogger`](File-Logger.md) — extends `\Psr\Log\AbstractLogger`.
- [`PDOLogger`](PDO-Logger.md) — extends `\Psr\Log\AbstractLogger`.

Extending `AbstractLogger` means each handler implements just `log()`; the
eight severity helpers are inherited and forward to that single method.

## The eight log levels

PSR-3 §1.1 enumerates exactly eight severity levels, in this order
(highest first):

| Level | `Psr\Log\LogLevel` constant | Severity |
| --- | --- | --- |
| Emergency | `LogLevel::EMERGENCY` (`"emergency"`) | system is unusable |
| Alert | `LogLevel::ALERT` (`"alert"`) | action must be taken immediately |
| Critical | `LogLevel::CRITICAL` (`"critical"`) | critical conditions |
| Error | `LogLevel::ERROR` (`"error"`) | runtime errors that do not require immediate action |
| Warning | `LogLevel::WARNING` (`"warning"`) | exceptional occurrences that are not errors |
| Notice | `LogLevel::NOTICE` (`"notice"`) | normal but significant events |
| Info | `LogLevel::INFO` (`"info"`) | informational messages |
| Debug | `LogLevel::DEBUG` (`"debug"`) | debug-level messages |

The package matches level inputs **case-insensitively** against these eight
constants. So `'error'`, `'ERROR'`, `'Error'` all work. Output is uppercased
for grep-friendliness (`[ERROR]` in file logs, `'ERROR'` in the database
`level` column).

Any other string raises `Psr\Log\InvalidArgumentException`, as permitted by
PSR-3 §1.1.

## Message and context — PSR-3 §1.2

PSR-3 §1.2 defines the placeholder syntax: a placeholder is `{name}`, where
`name` matches the regex `[A-Za-z0-9_\.]+`. Each handler in the package
implements that syntax through `HelperTrait::interpolate()`. The full rules
are documented in [Context Interpolation](Context-Interpolation.md).

```php
$logger->info('User {user} hit a {kind} error', [
    'user' => 'jane',
    'kind' => 'transient',
]);
// → "User jane hit a transient error"
```

## Exceptions in the context — PSR-3 §1.3

PSR-3 §1.3 says:

> If an Exception object is passed in the context data, it MUST be in the
> `'exception'` key. Logging exceptions is a common pattern and this allows
> implementors to extract a stack trace from the exception when the log
> backend supports it.
>
> Implementors MUST still ensure that the `'exception'` key is not
> interfered with should they get one from the user.

The package honours both clauses:

- It does **not** apply special parsing to the `'exception'` key beyond the
  generic `\Throwable` rendering.
- Any `\Throwable` in the context — under **any** key — is rendered as
  `"<Class>(<code>): <message> in <file>:<line>"` when its corresponding
  placeholder appears in the message.

If you want the stack trace too, format it yourself:

```php
$logger->error("payment failed: {exception}\n{trace}", [
    'exception' => $e,
    'trace'     => $e->getTraceAsString(),
]);
```

## `Stringable` messages — PSR-3 v3

PSR-3 v3 widened the message type from `string` to `string|\Stringable`. The
package accepts both:

```php
$logger->info('plain string');
$logger->info(new class implements \Stringable {
    public function __toString(): string { return 'a Stringable'; }
});
```

Placeholders work on the **rendered** string of a `Stringable` message,
so combining the two is fully supported.

## What MAY a logger do per PSR-3?

PSR-3 explicitly **permits** (but does not require) implementers to:

- raise `Psr\Log\InvalidArgumentException` from `log()` when the level is
  unknown — the package does;
- interpret context keys with special meaning to the storage backend, as
  long as the `'exception'` key is preserved — the package only renders
  `\Throwable` in context; it does not assign extra meaning to any key.

## What MUST NOT a logger do per PSR-3?

The specification mandates that:

- `log()` MUST NOT throw for ordinary backend failures (network down, disk
  full, …). The package's handlers honour this — see
  [Error Handling](Error-Handling.md).
- A logger MUST NOT modify the contents of the `$context` array. The package
  reads it but never writes back; even shared-context wrappers like the one
  in [Multi-Logger](Multi-Logger.md#same-shared-context-different-sinks) create
  a new merged array per call.

## Type-hint the contract, not the implementation

The single most important rule for PSR-3 consumers: depend on the **interface**.

```php
use Psr\Log\LoggerInterface;

final class PaymentService
{
    public function __construct(private LoggerInterface $logger) {}
}
```

Production code can be wired with `InitPHP\Logger\Logger`, tests with
`Psr\Log\NullLogger`, an integration test with an `ArrayLogger`-style helper
([Custom Handlers](Custom-Handlers.md#in-memory-arraylogger--perfect-for-tests)) — none of
the consumer code changes.

## Related

- [Context Interpolation](Context-Interpolation.md) — the per-value rendering
  rules in detail.
- [Error Handling](Error-Handling.md) — when the package throws and when it
  silently degrades.
- [Custom Handlers › Guidelines](Custom-Handlers.md#guidelines-for-handler-authors)
  — checklist for writing your own PSR-3-compliant handler.
- The official spec: <https://www.php-fig.org/psr/psr-3/>.
