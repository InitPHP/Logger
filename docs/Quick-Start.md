# Quick Start

This page walks you from a fresh `composer require` to a working logger in
under five minutes. If you have not installed the package yet, start with
[Installation](Installation.md).

## Choose a handler

The package ships two concrete handlers and one multiplexer. Pick whichever
matches your storage need; they all expose the identical PSR-3 surface.

| You want to… | Use… |
| --- | --- |
| append log lines to a local file | [`FileLogger`](File-Logger.md) |
| insert log rows into a database | [`PDOLogger`](PDO-Logger.md) |
| do both at once (or combine any PSR-3 handlers) | [`Logger`](Multi-Logger.md) |
| send logs somewhere else (syslog, Slack, in-memory buffer…) | a [Custom Handler](Custom-Handlers.md) |

## File logging in three lines

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use InitPHP\Logger\FileLogger;

$logger = new FileLogger(['path' => __DIR__ . '/logs/app.log']);

$logger->info('Hello from InitPHP Logger');
```

Run the script. You should now see:

```text
$ cat logs/app.log
2026-05-24T14:08:22+03:00 [INFO] Hello from InitPHP Logger
```

The `logs/` directory is created automatically; you do not have to `mkdir` it
manually. Each call writes exactly one line ending with `PHP_EOL`.

## Database logging

`PDOLogger` requires you to supply a configured `PDO` instance and the name
of a table that has at minimum `level`, `message` and `date` columns.

```sql
CREATE TABLE logs (
    id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level   VARCHAR(10) NOT NULL,
    message TEXT NOT NULL,
    date    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use InitPHP\Logger\PDOLogger;

$pdo = new PDO('mysql:host=localhost;dbname=app;charset=utf8mb4', 'app', 'secret', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$logger = new PDOLogger(['pdo' => $pdo, 'table' => 'logs']);

$logger->error('User {user} caused an error.', ['user' => 'jane']);
```

After the call:

| level | message | date |
| --- | --- | --- |
| `ERROR` | `User jane caused an error.` | `2026-05-24 14:08:22` |

Full schema variants for MySQL / PostgreSQL / SQLite are in
[PDOLogger › DDL examples](PDO-Logger.md#ddl-examples).

## Multi-handler logging

`Logger` accepts any number of PSR-3 handlers and forwards every call to all
of them, in registration order.

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
    new FileLogger(['path' => __DIR__ . '/logs/app.log']),
    new PDOLogger(['pdo' => $pdo, 'table' => 'logs'])
);

$logger->warning('cache miss for key {key}', ['key' => 'user:42']);
// → goes to BOTH the log file and the logs table
```

Every PSR-3 method works the same way through the multiplexer; see
[Multi-Logger](Multi-Logger.md) for ordering, fault isolation, and exception
semantics.

## All eight PSR-3 levels

The package exposes the full PSR-3 surface — eight severity helpers plus the
generic `log()` method:

```php
$logger->emergency('system unusable');
$logger->alert    ('action required immediately');
$logger->critical ('critical condition');
$logger->error    ('runtime error');
$logger->warning  ('exceptional condition, not an error');
$logger->notice   ('normal but significant');
$logger->info     ('informational');
$logger->debug    ('debug-level detail');

// Equivalent low-level form:
$logger->log(\Psr\Log\LogLevel::ERROR, 'runtime error');
```

Unknown levels throw `\Psr\Log\InvalidArgumentException`. The package
normalises levels to uppercase in its output (`ERROR`, `WARNING`, …); inputs
are matched case-insensitively against the eight PSR-3 constants.

## Inject `LoggerInterface`, not a concrete class

The whole point of PSR-3 is that consumers depend on the **interface**:

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

Anyone can pass a `FileLogger`, a `PDOLogger`, a `Logger`, a `NullLogger` in
tests, Monolog, or their own handler. Your service does not need to know.

## Where to go next

- Detailed handler docs:
  [FileLogger](File-Logger.md) · [PDOLogger](PDO-Logger.md) ·
  [Multi-Logger](Multi-Logger.md) · [Custom Handlers](Custom-Handlers.md)
- PSR-3 specifics: [PSR-3 Compliance](PSR-3-Compliance.md),
  [Context Interpolation](Context-Interpolation.md),
  [Error Handling](Error-Handling.md)
- Real-world patterns: [Recipes](Recipes.md)
- Writing tests against the logger: [Testing Your Logging](Testing-Your-Logging.md)
- Full method reference: [API Reference](API-Reference.md)
