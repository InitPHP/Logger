# `Logger` (multi-handler multiplexer)

`InitPHP\Logger\Logger` is a tiny **fan-out multiplexer**: it accepts one or
more `Psr\Log\LoggerInterface` instances and forwards every PSR-3 call to all
of them, in the order they were supplied.

It is the only public class in the package that you typically pass around
through your application — the actual handlers (`FileLogger`, `PDOLogger`,
custom handlers) plug into it.

## Constructor

```php
public function __construct(\Psr\Log\LoggerInterface ...$loggers);
```

- Variadic `LoggerInterface` — PHP itself enforces the type. Anything else
  raises `TypeError` immediately.
- At least one logger is required. An empty argument list throws
  `\InvalidArgumentException`.

```php
use InitPHP\Logger\FileLogger;
use InitPHP\Logger\Logger;
use InitPHP\Logger\PDOLogger;

$logger = new Logger(
    new FileLogger(['path' => __DIR__ . '/logs/app.log']),
    new PDOLogger(['pdo' => $pdo, 'table' => 'logs'])
);
```

## Dispatch semantics

Every PSR-3 method (`emergency()`, …, `debug()`, and `log()`) forwards directly
to each inner logger:

```php
$logger->error('boom', ['k' => 'v']);
// is equivalent to, for handlers [a, b, c]:
$a->log('error', 'boom', ['k' => 'v']);
$b->log('error', 'boom', ['k' => 'v']);
$c->log('error', 'boom', ['k' => 'v']);
```

The order is the registration order, and it is stable.

## Exception propagation

`Logger` does **not** catch exceptions thrown by inner handlers:

- PSR-3 §1.1 explicitly permits `Psr\Log\InvalidArgumentException` for unknown
  levels, and you almost always want to see those.
- Other exceptions (e.g. `PDOException` from a database outage) propagate out
  of `Logger::log()` and abort the rest of the fan-out.

If your handlers can fail independently and you want fault isolation — keep
writing to the file even when the database is down — wrap the fragile handler:

```php
final class TolerantLogger implements \Psr\Log\LoggerInterface
{
    use \Psr\Log\LoggerTrait;

    public function __construct(private \Psr\Log\LoggerInterface $inner) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        try {
            $this->inner->log($level, $message, $context);
        } catch (\Throwable $e) {
            error_log('logger handler failed: ' . $e->getMessage());
        }
    }
}

$logger = new Logger(
    new FileLogger(['path' => __DIR__ . '/logs/app.log']),
    new TolerantLogger(new PDOLogger(['pdo' => $pdo, 'table' => 'logs']))
);
```

## Fault isolation

A common production pattern is "always write to the file; best-effort to the
database":

1. Put `FileLogger` **first** so it always runs.
2. Wrap `PDOLogger` in your own `TolerantLogger`-style decorator so its
   failures do not abort the chain.

Because `Logger` is iterative, the order matters: if the second handler in the
chain throws and is not wrapped, the third handler will not be reached.

## Introspecting the chain

`getLoggers()` returns the registered handlers in order, which is useful for
diagnostics and for tests:

```php
$logger->getLoggers();   // [FileLogger, PDOLogger]
```

The returned array is a copy of the internal list; modifying it does not
affect the logger.

## Composing with other PSR-3 packages

`Logger` does not care where its handlers come from. You can mix InitPHP
handlers with third-party ones, e.g. Monolog:

```php
use InitPHP\Logger\FileLogger;
use InitPHP\Logger\Logger;
use Monolog\Logger as Monolog;
use Monolog\Handler\StreamHandler;

$monolog = new Monolog('app');
$monolog->pushHandler(new StreamHandler('php://stderr'));

$logger = new Logger(
    new FileLogger(['path' => __DIR__ . '/logs/app.log']),
    $monolog
);
```

The only contract is `Psr\Log\LoggerInterface`.
