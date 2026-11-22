# Multi-Logger (`Logger`)

`InitPHP\Logger\Logger` is a **fan-out multiplexer**: it accepts one or more
`Psr\Log\LoggerInterface` instances and forwards every PSR-3 call to all of
them, in the order they were supplied.

It is the only public class in the package that you typically pass around
through your application — the actual handlers (`FileLogger`, `PDOLogger`,
custom handlers, third-party PSR-3 implementations) plug into it.

```php
use InitPHP\Logger\FileLogger;
use InitPHP\Logger\Logger;
use InitPHP\Logger\PDOLogger;

$logger = new Logger(
    new FileLogger(['path' => __DIR__ . '/logs/app.log']),
    new PDOLogger(['pdo' => $pdo, 'table' => 'logs'])
);

$logger->warning('cache miss for key {key}', ['key' => 'user:42']);
// Goes to BOTH the log file and the logs table.
```

## Constructor

```php
public function __construct(\Psr\Log\LoggerInterface ...$loggers)
```

`Logger` uses a **variadic** `LoggerInterface` parameter. PHP itself enforces
the type — anything that is not a `Psr\Log\LoggerInterface` raises `TypeError`
at the call site, before the constructor body runs.

| Constructor input | Result |
| --- | --- |
| zero arguments | `\InvalidArgumentException` |
| at least one `LoggerInterface` | OK |
| any non-`LoggerInterface` argument | `TypeError` (PHP-level) |

```php
new Logger();
// ✗ InvalidArgumentException: InitPHP\Logger\Logger requires at least one Psr\Log\LoggerInterface instance.

new Logger('not-a-logger');
// ✗ TypeError: Argument #1 ... must be of type Psr\Log\LoggerInterface, string given

new Logger(new FileLogger(['path' => '/tmp/a.log']), new NullLogger());
// ✓
```

## Dispatch semantics

Every PSR-3 method (`emergency()`, …, `debug()`, and the generic `log()`)
forwards directly to each inner logger:

```php
$logger->error('boom', ['k' => 'v']);
```

is, for inner handlers `[a, b, c]`, equivalent to:

```php
$a->log('error', 'boom', ['k' => 'v']);
$b->log('error', 'boom', ['k' => 'v']);
$c->log('error', 'boom', ['k' => 'v']);
```

The order is the **registration order**, and it is stable. Because the
multiplexer extends `\Psr\Log\AbstractLogger`, all eight level helpers are
inherited from `AbstractLogger` and themselves delegate to `log()` —
overriding `log()` is sufficient.

## Exception propagation

`Logger::log()` does **not** catch exceptions thrown by inner handlers:

- PSR-3 §1.1 explicitly permits `Psr\Log\InvalidArgumentException` for
  unknown log levels, and you almost always want to see those.
- Other exceptions (e.g. `PDOException` from a database outage, or
  `RuntimeException` from a faulty custom handler) propagate out of
  `Logger::log()` and abort the rest of the fan-out.

This is intentional: silently swallowing exceptions makes outages invisible
and debugging harder. If your handlers can fail independently and you want
fault isolation, wrap the fragile one explicitly — see
[Recipes › Database logger that survives outages](Recipes.md#database-logger-that-survives-outages).

## Ordering matters when failures matter

Because `Logger` iterates serially and lets exceptions propagate, the order
of handlers determines what runs when something breaks:

```php
$logger = new Logger(
    new FileLogger(['path' => '/var/log/app.log']),                   // 1
    new TolerantLogger(new PDOLogger(['pdo' => $pdo, 'table' => 'logs'])), // 2 (decorated)
);
```

- If handler 1 throws, handler 2 does not run.
- If handler 2 is decorated to swallow its own failures, handler 1 is
  unaffected by database problems.

A safe production pattern is therefore: **fastest, most reliable handler
first; potentially-flaky handlers later, decorated to swallow failures**.

## Introspecting the chain

`getLoggers()` returns the registered handlers, in order:

```php
$logger->getLoggers();   // [FileLogger, PDOLogger]
```

The returned array is a copy of the internal list; modifying it does not
affect the multiplexer. The method is convenient in diagnostics, tests, and
administrative tooling.

## Composing with other PSR-3 packages

`Logger` does not care where its handlers come from. You can mix InitPHP
handlers with third-party PSR-3 implementations, for example Monolog:

```php
use InitPHP\Logger\FileLogger;
use InitPHP\Logger\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as Monolog;

$monolog = new Monolog('app');
$monolog->pushHandler(new StreamHandler('php://stderr'));

$logger = new Logger(
    new FileLogger(['path' => __DIR__ . '/logs/app.log']),
    $monolog                                             // <-- Monolog
);
```

The only contract is `Psr\Log\LoggerInterface`.

## Same shared context, different sinks

A common pattern is to attach contextual data (request id, user id, …) once
and feed it through every handler. The package itself does not ship a
context-decorator, but writing one over `Logger` is a few lines:

```php
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class WithContext extends AbstractLogger
{
    public function __construct(
        private LoggerInterface $inner,
        private array $extra,
    ) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, $this->extra + $context);
    }
}

$logger = new WithContext(
    new Logger(
        new FileLogger(['path' => '/var/log/app.log']),
        new PDOLogger(['pdo' => $pdo, 'table' => 'logs'])
    ),
    ['request_id' => $requestId]
);

$logger->info('user {user_id} logged in', ['user_id' => 7]);
// File and DB both see {user_id} = 7 AND {request_id} = $requestId.
```

## Complete example

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use InitPHP\Logger\FileLogger;
use InitPHP\Logger\Logger;
use InitPHP\Logger\PDOLogger;

$pdo = new PDO('mysql:host=localhost;dbname=app', 'app', 'secret', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$logger = new Logger(
    new FileLogger(['path' => __DIR__ . '/logs/app-{year}-{month}-{day}.log']),
    new PDOLogger(['pdo' => $pdo, 'table' => 'logs'])
);

$logger->info('service booted in {ms}ms', ['ms' => 42]);
$logger->error('payment {id} failed', ['id' => 9182]);

// Both records are now in logs/app-2026-05-24.log AND in the logs table.
```

## Related

- [FileLogger](File-Logger.md), [PDOLogger](PDO-Logger.md),
  [Custom Handlers](Custom-Handlers.md).
- [Error Handling](Error-Handling.md) — package-wide exception policy.
- [Recipes](Recipes.md) — multi-handler patterns in production.
- [API Reference › Logger](API-Reference.md#logger).
