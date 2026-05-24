# Getting Started

This guide walks you from `composer install` to a first working log line in
under five minutes.

## 1. Install

```bash
composer require initphp/logger
```

The package requires PHP 8.0 or newer and pulls `psr/log:^3.0` in. No other
runtime dependencies are mandatory; `ext-pdo` is only needed if you intend to
use `PDOLogger`.

## 2. Pick a handler

| Handler | Storage | When to use |
| --- | --- | --- |
| `InitPHP\Logger\FileLogger` | Local filesystem | Default. Quick to set up, easy to tail with `tail -f`, plays well with `logrotate`. |
| `InitPHP\Logger\PDOLogger`  | SQL database     | When logs need to be queryable by your operations team or by application code. |
| `InitPHP\Logger\Logger`     | (multiplexer)    | When you want both — or any other combination of PSR-3 handlers. |

You can also bring your own handler — see
[`05-custom-handlers.md`](05-custom-handlers.md).

## 3. Write the first log line

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use InitPHP\Logger\FileLogger;

$logger = new FileLogger([
    'path' => __DIR__ . '/logs/app.log',
]);

$logger->info('Hello from InitPHP Logger');
$logger->error('User {user} hit a {kind} error', [
    'user' => 'jane',
    'kind' => 'transient',
]);
```

Run the script and inspect the file:

```
2026-05-24T14:08:22+03:00 [INFO] Hello from InitPHP Logger
2026-05-24T14:08:22+03:00 [ERROR] User jane hit a transient error
```

Notes:

- The `logs/` directory is created automatically; you do not need to `mkdir` it.
- Every log line ends with `PHP_EOL`. Concurrent writers cannot interleave the
  bytes of a single record.
- Failure to write does **not** throw — PSR-3 method calls must remain
  side-effect-free for application flow. A `FileLogger` write failure is
  surfaced through `error_log()`.

## 4. Type-hint the contract, not the handler

The whole point of PSR-3 is that consumers depend on the **interface**, not on
a specific package. So when wiring loggers through your application:

```php
use Psr\Log\LoggerInterface;

final class PaymentService
{
    public function __construct(private LoggerInterface $logger) {}

    public function charge(int $userId): void
    {
        $this->logger->info('charging user {id}', ['id' => $userId]);
    }
}
```

Anyone can pass an `InitPHP\Logger\FileLogger`, an `InitPHP\Logger\Logger`,
Monolog, the `NullLogger` in tests, or their own implementation. Your service
does not need to care.

## 5. Where to go next

- [Send the same record to several places](04-multi-logger.md)
- [Inspect every option of `FileLogger`](02-file-logger.md)
- [Set up a relational backend with `PDOLogger`](03-pdo-logger.md)
- [Understand context placeholders and `{exception}`](06-psr3-context.md)
- [Build your own handler](05-custom-handlers.md)
- [Cookbook of common setups](07-recipes.md)
- [Test code that uses this logger](08-testing-your-logging.md)
