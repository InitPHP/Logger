# Changelog

All notable changes to `initphp/logger` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Full PSR-3 v3 compliance: every handler now implements
  `Psr\Log\LoggerInterface` directly, including `Logger`, which previously
  relied on `__call()` for dispatch.
- `Throwable` rendering for context placeholders — any `\Throwable` value in
  the context array is rendered as `Class(code): message in file:line`.
- `string|\Stringable` accepted as message type across all handlers.
- `FileLogger`:
  - Automatic creation of the parent directory (mode `0775`).
  - Concurrent-safe writes via `FILE_APPEND | LOCK_EX`.
  - `getPath()` accessor returning the token-interpolated path.
- `PDOLogger`:
  - Table-name validation against `/^[A-Za-z_][A-Za-z0-9_]*$/` to prevent
    identifier injection.
  - Distinct, specific error messages for each invalid construction path.
  - `getTable()` accessor.
- `Logger`:
  - At least one logger is now required at construction time.
  - Variadic `LoggerInterface` typing — non-`LoggerInterface` arguments raise
    `TypeError` instead of being silently dropped.
  - `getLoggers()` accessor.
- Comprehensive PHPUnit test suite (45 tests, 80 assertions).
- PHPStan (`level: max`) and PHP-CS-Fixer (PSR-12) configured and CI-enforced.
- GitHub Actions CI matrix: PHP 8.0 / 8.1 / 8.2 / 8.3 / 8.4.
- `docs/` directory with eight topic guides (getting started, file logger,
  PDO logger, multi-logger, custom handlers, PSR-3 context, recipes, testing).

### Changed

- **Minimum PHP version raised to 8.0** (was 5.6). PHP 8.0+ is required for
  PSR-3 v3 anyway.
- **`psr/log` constraint bumped to `^3.0`** (was the pinned `1.1.4`).
- All source files now declare `strict_types=1` and use property/parameter/
  return-type declarations.
- `Logger` is now `final`. Sub-classing it was never documented; users who
  need to customise fan-out behaviour should compose with `LoggerInterface`.
- Log lines emitted by `FileLogger` now terminate with `PHP_EOL` instead of
  beginning with one — the previous behaviour produced a stray empty line at
  the top of every log file and made tailing awkward.

### Fixed

- `HelperTrait::$levels` no longer contains `LogLevel::WARNING` twice; the
  list now mirrors the canonical eight PSR-3 levels in severity order.
- `FileLogger` no longer silently swallows write failures with the `@`
  operator; failures are reported through `error_log()`.
- `FileLogger` rejects missing/empty/non-string `path` options at
  construction with a clear `InvalidArgumentException`, instead of failing
  obliquely at the first write.
- `PDOLogger` raises specific, actionable `InvalidArgumentException` messages
  for each missing or wrongly-typed option (previously a single generic
  "It must be a PDO object." message was thrown for several distinct
  failures).
- `PDOLogger` uses `Psr\Log\InvalidArgumentException` consistently per
  PSR-3 §1.1 (the previous code imported it but threw the SPL variant).
- `PDOLogger::__destruct` removed — manually nulling an injected PDO is not
  the logger's responsibility and could mislead readers about ownership.

### Removed

- `Logger::__call()` magic dispatch. The class now implements
  `LoggerInterface` properly.

  **Behaviour change for callers:** Previously, calling an unknown method on
  `Logger` (e.g. `$logger->banana()`) silently no-op'd via `__call()`.
  Such calls now raise `Error: Call to undefined method
  InitPHP\Logger\Logger::banana()` at the PHP level. Legitimate PSR-3 calls
  (`emergency()` … `debug()`, `log()`) are unaffected.

## 1.0 - 2022-03-11

Initial release.
