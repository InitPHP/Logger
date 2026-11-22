# InitPHP Logger

A small, focused, PSR-3 compliant logger for PHP 8.2+. Ships with two built-in
handlers and a tiny multiplexer that fans every log record out to several
handlers at once.

[![Designed & Maintained with Tan](https://muhammetsafak.com/badges/designed-maintained-with-tan.svg)](https://muhammetsafak.com/tan/)
[![Latest Stable Version](https://poser.pugx.org/initphp/logger/v)](https://packagist.org/packages/initphp/logger)
[![Total Downloads](https://poser.pugx.org/initphp/logger/downloads)](https://packagist.org/packages/initphp/logger)
[![License](https://poser.pugx.org/initphp/logger/license)](https://packagist.org/packages/initphp/logger)
[![PHP Version Require](https://poser.pugx.org/initphp/logger/require/php)](https://packagist.org/packages/initphp/logger)

## At a glance

- **`FileLogger`** — appends each record as a single line to a file, with
  optional date-token placeholders in the path (`{year}/{month}/{day}/...`).
- **`PDOLogger`** — inserts each record as a row in a relational table, using
  prepared statements. Works with any PDO driver (MySQL, PostgreSQL, SQLite…).
- **`Logger`** — accepts any number of `Psr\Log\LoggerInterface` instances and
  forwards every call to all of them, in registration order.

Everything implements `Psr\Log\LoggerInterface` (PSR-3 v3), so you can drop the
package into any framework or library that consumes that contract — or compose
it with handlers from other PSR-3 packages.

## Requirements

| Requirement | Version |
| --- | --- |
| PHP | `>= 8.2` |
| [`psr/log`](https://packagist.org/packages/psr/log) | `^3.0` |
| `ext-pdo` | Required only for `PDOLogger` |

## Installation

```bash
composer require initphp/logger
```

## Quick start

```php
require __DIR__ . '/vendor/autoload.php';

use InitPHP\Logger\FileLogger;
use InitPHP\Logger\Logger;

$logger = new Logger(
    new FileLogger(['path' => __DIR__ . '/logs/app.log'])
);

$logger->info('Service booted in {ms}ms', ['ms' => 42]);
$logger->error('Payment {id} failed', ['id' => 9182]);
```

Produces, for example:

```
2026-05-24T14:08:22+03:00 [INFO] Service booted in 42ms
2026-05-24T14:08:22+03:00 [ERROR] Payment 9182 failed
```

## Handlers

### FileLogger

```php
use InitPHP\Logger\FileLogger;

$logger = new FileLogger([
    'path' => __DIR__ . '/logs/app-{year}-{month}-{day}.log',
]);
```

Path tokens (`{year}`, `{month}`, `{day}`, `{hour}`, `{minute}`, `{second}`) are
resolved once, at construction time, against the process clock. The parent
directory is created automatically (mode `0775`) if it does not already exist.
Writes use `FILE_APPEND | LOCK_EX`, so concurrent processes do not interleave
bytes within a single record.

Full reference: [`docs/File-Logger.md`](docs/File-Logger.md).

### PDOLogger

```php
use InitPHP\Logger\PDOLogger;

$pdo = new PDO('mysql:host=localhost;dbname=app;charset=utf8mb4', 'app', 'secret');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$logger = new PDOLogger(['pdo' => $pdo, 'table' => 'logs']);

$logger->error('User {user} caused an error.', ['user' => 'muhammetsafak']);
// INSERT INTO logs (level, message, date) VALUES ('ERROR', 'User muhammetsafak caused an error.', '2026-05-24 14:08:22')
```

Reference DDL for MySQL:

```sql
CREATE TABLE `logs` (
    `id`      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `level`   ENUM('EMERGENCY','ALERT','CRITICAL','ERROR','WARNING','NOTICE','INFO','DEBUG') NOT NULL,
    `message` TEXT NOT NULL,
    `date`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_logs_level_date` (`level`, `date`)
) ENGINE = InnoDB CHARSET = utf8mb4 COLLATE utf8mb4_general_ci;
```

PostgreSQL and SQLite variants are documented in
[`docs/PDO-Logger.md`](docs/PDO-Logger.md).

### Multi-handler logging

```php
use InitPHP\Logger\FileLogger;
use InitPHP\Logger\Logger;
use InitPHP\Logger\PDOLogger;

$logger = new Logger(
    new FileLogger(['path' => __DIR__ . '/logs/app.log']),
    new PDOLogger(['pdo' => $pdo, 'table' => 'logs'])
);

$logger->warning('cache miss for key {key}', ['key' => 'user:42']);
// Goes to BOTH the file and the database table.
```

`Logger` accepts any `Psr\Log\LoggerInterface`, including handlers from other
PSR-3 packages — see [`docs/Custom-Handlers.md`](docs/Custom-Handlers.md).

## PSR-3 surface

Every handler exposes the full PSR-3 API:

```php
$logger->emergency(string|\Stringable $message, array $context = []): void;
$logger->alert    (string|\Stringable $message, array $context = []): void;
$logger->critical (string|\Stringable $message, array $context = []): void;
$logger->error    (string|\Stringable $message, array $context = []): void;
$logger->warning  (string|\Stringable $message, array $context = []): void;
$logger->notice   (string|\Stringable $message, array $context = []): void;
$logger->info     (string|\Stringable $message, array $context = []): void;
$logger->debug    (string|\Stringable $message, array $context = []): void;
$logger->log      ($level, string|\Stringable $message, array $context = []): void;
```

Context placeholders (`{name}`) are expanded with the matching key from
`$context`. Booleans render as `true` / `false`, `null` renders as the empty
string, `\Throwable` values render as `Class(code): message in file:line`,
and anything implementing `__toString()` is cast to string. Arrays and
non-stringable objects are skipped — see
[`docs/Context-Interpolation.md`](docs/Context-Interpolation.md).

Unknown log levels throw `Psr\Log\InvalidArgumentException`, per PSR-3 §1.1.

## Documentation

Topic-by-topic guides live in [`docs/`](docs/):

- [`Home.md`](docs/Home.md)
- [`Installation.md`](docs/Installation.md)
- [`Quick-Start.md`](docs/Quick-Start.md)
- [`File-Logger.md`](docs/File-Logger.md)
- [`PDO-Logger.md`](docs/PDO-Logger.md)
- [`Multi-Logger.md`](docs/Multi-Logger.md)
- [`Custom-Handlers.md`](docs/Custom-Handlers.md)
- [`Context-Interpolation.md`](docs/Context-Interpolation.md)
- [`Recipes.md`](docs/Recipes.md)
- [`Testing-Your-Logging.md`](docs/Testing-Your-Logging.md)
- [`PSR-3-Compliance.md`](docs/PSR-3-Compliance.md)
- [`Error-Handling.md`](docs/Error-Handling.md)
- [`Troubleshooting.md`](docs/Troubleshooting.md)
- [`API-Reference.md`](docs/API-Reference.md)
- [`Migration-Guide.md`](docs/Migration-Guide.md)
- [`FAQ.md`](docs/FAQ.md)

## Testing the package itself

```bash
composer install
composer test         # PHPUnit
composer phpstan      # PHPStan (level max)
composer cs-check     # PHP-CS-Fixer dry-run
composer ci           # all of the above
```

## Contributing

Please read the org-wide
[`CONTRIBUTING.md`](https://github.com/InitPHP/.github/blob/main/CONTRIBUTING.md)
before opening a pull request. Bug reports and feature ideas go through
[Issues](https://github.com/InitPHP/Logger/issues) and
[Discussions](https://github.com/orgs/InitPHP/discussions).

## Security

If you discover a security vulnerability, please follow the instructions in the
org-wide
[`SECURITY.md`](https://github.com/InitPHP/.github/blob/main/SECURITY.md). Do
**not** open a public issue.

## License

Released under the [MIT License](LICENSE). Copyright © InitPHP.
