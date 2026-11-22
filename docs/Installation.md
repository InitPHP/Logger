# Installation

`initphp/logger` is distributed through Composer. The package has a single
runtime dependency (`psr/log`), and adds an optional dependency on
`ext-pdo` when you intend to use the database handler.

## Requirements

| Requirement | Version | Notes |
| --- | --- | --- |
| **PHP** | `^8.2` | Required by the package (`composer.json`). `psr/log` v3 itself needs PHP 8.0 or later. |
| **`psr/log`** | `^3.0` | Pulled in automatically. |
| **`ext-pdo`** | any | Only required if you use [PDOLogger](PDO-Logger.md). |
| **`ext-pdo_<driver>`** | any | The driver matching your database (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, …). |

If you are on PHP 8.1 or older, you cannot use this version of the package. The
2.0.0 release still supported PHP 8.0 and 8.1; PHP 8.2 became the minimum after
that release (see the [Migration Guide](Migration-Guide.md)).

## Install via Composer

```bash
composer require initphp/logger
```

Composer installs the package together with its single runtime dependency,
`psr/log` `^3.0`.

The package registers under the namespace `InitPHP\Logger\`, mapped to `src/`
via PSR-4 autoloading. You do not need to require any file manually — letting
Composer's `vendor/autoload.php` handle it is sufficient:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use InitPHP\Logger\FileLogger;

$logger = new FileLogger(['path' => __DIR__ . '/logs/app.log']);
```

## Verifying the installation

A one-liner is enough:

```bash
php -r "require 'vendor/autoload.php'; (new InitPHP\Logger\FileLogger(['path' => '/tmp/initphp.log']))->info('it works'); echo file_get_contents('/tmp/initphp.log');"
```

You should see something like:

```text
2026-05-24T14:08:22+03:00 [INFO] it works
```

If you see no output and no error, check that `/tmp` is writable and that the
log line was actually written (the file may be empty if the user lacks write
permissions; PSR-3 calls do not throw on filesystem failures — see
[Error Handling](Error-Handling.md)).

## Updating

```bash
composer update initphp/logger
```

Every release follows [Semantic Versioning](https://semver.org/). The
[Migration Guide](Migration-Guide.md) lists every breaking change with its
upgrade path.

## Uninstalling

```bash
composer remove initphp/logger
```

Composer also offers to remove `psr/log` if nothing else in your project
depends on it.

## What's next

- [Quick Start](Quick-Start.md) — your first log line, end to end.
- [PSR-3 Compliance](PSR-3-Compliance.md) — what contract the package implements,
  and the rules around it.
- [FAQ](FAQ.md) — answers to common pre-install questions.
