# InitPHP Logger — Wiki

Welcome to the official documentation for **`initphp/logger`** — a small,
focused, PSR-3 v3 compliant logger for PHP 8.2+.

The package ships three classes:

| Class | Purpose |
| --- | --- |
| [`FileLogger`](File-Logger.md) | Appends each record as a single line to a file. |
| [`PDOLogger`](PDO-Logger.md) | Inserts each record as a row in a relational table. |
| [`Logger`](Multi-Logger.md) | Fan-out multiplexer: forwards every PSR-3 call to several handlers at once. |

Every class implements `Psr\Log\LoggerInterface`, so you can drop the package
into any framework or library that consumes that contract, or compose it with
handlers from other PSR-3 packages.

```bash
composer require initphp/logger
```

```php
use InitPHP\Logger\FileLogger;

$logger = new FileLogger(['path' => __DIR__ . '/logs/app.log']);
$logger->info('Service booted in {ms}ms', ['ms' => 42]);
```

```
2026-05-24T14:08:22+03:00 [INFO] Service booted in 42ms
```

## Start here

- **New to the package?** Read [Installation](Installation.md), then
  [Quick Start](Quick-Start.md).
- **Picking a handler?** Compare [FileLogger](File-Logger.md),
  [PDOLogger](PDO-Logger.md) and the [Multi-Logger](Multi-Logger.md).
- **Writing your own handler?** See [Custom Handlers](Custom-Handlers.md).
- **PSR-3 questions?** Jump to [PSR-3 Compliance](PSR-3-Compliance.md) and
  [Context Interpolation](Context-Interpolation.md).
- **Looking for a specific method?** The complete
  [API Reference](API-Reference.md) lists every class member.

## Navigation

### Getting Started
- [Installation](Installation.md)
- [Quick Start](Quick-Start.md)

### Handlers
- [FileLogger](File-Logger.md)
- [PDOLogger](PDO-Logger.md)
- [Multi-Logger (`Logger`)](Multi-Logger.md)
- [Custom Handlers](Custom-Handlers.md)

### PSR-3 Behaviour
- [PSR-3 Compliance](PSR-3-Compliance.md)
- [Context Interpolation](Context-Interpolation.md)
- [Error Handling](Error-Handling.md)

### Practical Guides
- [Recipes](Recipes.md)
- [Testing Your Logging](Testing-Your-Logging.md)
- [Troubleshooting](Troubleshooting.md)

### Reference
- [API Reference](API-Reference.md)
- [Migration Guide](Migration-Guide.md)
- [FAQ](FAQ.md)

## At a glance — feature matrix

| Capability | `FileLogger` | `PDOLogger` | `Logger` |
| --- | :---: | :---: | :---: |
| PSR-3 v3 compliant | ✅ | ✅ | ✅ |
| Accepts `string\|\Stringable` messages | ✅ | ✅ | ✅ |
| Renders `\Throwable` context values | ✅ | ✅ | (delegates) |
| Concurrent-safe writes | ✅ (`LOCK_EX`) | ✅ (DB-level) | (delegates) |
| Auto-creates parent directory | ✅ | n/a | n/a |
| Validates configuration eagerly | ✅ | ✅ | ✅ |
| Path / table tokenisation | `{year}`/`{month}`/… | identifier regex | n/a |
| Fan-out to multiple targets | ❌ | ❌ | ✅ |

## Package metadata

- **License**: MIT
- **Minimum PHP**: 8.2
- **PSR-3 contract**: `psr/log` `^3.0`
- **Packagist**: [`initphp/logger`](https://packagist.org/packages/initphp/logger)
- **Source**: [github.com/InitPHP/Logger](https://github.com/InitPHP/Logger)
- **Issues**: [github.com/InitPHP/Logger/issues](https://github.com/InitPHP/Logger/issues)
- **Discussions**: [github.com/orgs/InitPHP/discussions](https://github.com/orgs/InitPHP/discussions)
- **Security**: [`SECURITY.md`](https://github.com/InitPHP/.github/blob/main/SECURITY.md)

If something in this wiki is unclear, ambiguous, or wrong, please
[open an issue](https://github.com/InitPHP/Logger/issues/new) — documentation
fixes are reviewed eagerly.
