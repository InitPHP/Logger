# Migration Guide

This page lists every breaking change between releases and the exact steps
to update.

## From 2.0.x → current `main` (unreleased)

| Before | After |
| --- | --- |
| PHP 8.0+ required | **PHP 8.2+ required** (`^8.2`) |

PHP 8.0 and 8.1 are no longer supported; CI covers PHP 8.2, 8.3, 8.4 and 8.5.
The public API is unchanged — only the PHP version constraint moved. If you
are on PHP 8.0 or 8.1, stay on the 2.0.x releases or upgrade PHP first. The
project's code style also moved to PER-CS, which affects contributors only,
not users of the package.

## From 1.0.x → 2.0.0

The 2.0.0 release modernises the package to PHP 8.0+ and PSR-3 v3, fixes a
batch of long-standing bugs, and tightens type safety. Most legitimate
usages continue to work unchanged.

### Required environment changes

| Before | After |
| --- | --- |
| PHP 5.6+ | **PHP 8.0+** required |
| `psr/log` `1.1.4` (exact pin) | **`psr/log ^3.0`** |

If your application uses `psr/log` v1 transitively (from older libraries),
you may need to upgrade those dependencies too. Composer will refuse to
resolve conflicting `psr/log` constraints with a clear error message.

### Behavioural changes you may notice

#### 1. Unknown method calls on `Logger` now throw

`Logger::__call()` has been removed. The class now implements
`Psr\Log\LoggerInterface` directly.

```php
// 1.0.x — silently no-op
$logger->banana();

// 2.0.0 — fatal error
$logger->banana();
// → Error: Call to undefined method InitPHP\Logger\Logger::banana()
```

**Legitimate PSR-3 calls** (`emergency()` … `debug()`, `log()`) are
unaffected.

#### 2. `FileLogger` now requires `path`

```php
// 1.0.x — silently produced PHP warnings + likely failed at write time
new FileLogger([]);

// 2.0.0 — throws at construction
new FileLogger([]);
// → InvalidArgumentException: FileLogger requires a non-empty string "path" option.
```

If you previously relied on lazy validation, update your call sites.

#### 3. `PDOLogger` now validates the table name

```php
// 1.0.x — accepted any string
new PDOLogger(['pdo' => $pdo, 'table' => 'log records']);

// 2.0.0 — throws
new PDOLogger(['pdo' => $pdo, 'table' => 'log records']);
// → InvalidArgumentException: PDOLogger "table" option "log records" is not a valid SQL identifier; expected /^[A-Za-z_][A-Za-z0-9_]*$/.
```

If your existing table name does not match `/^[A-Za-z_][A-Za-z0-9_]*$/`, you
have three options:

1. **Rename the table** to use only ASCII letters, digits and underscores.
   This is the recommended path.
2. **Subclass `PDOLogger`** and override the constructor to skip the regex
   check — you then take responsibility for safe identifier handling.
3. Stay on 1.0.x. (Not recommended; 1.0.x is unmaintained.)

#### 4. `FileLogger` output format

The newline used to be at the **start** of each line; it is now at the **end**.

| Before (1.0.x) | After (2.0.0) |
| --- | --- |
| `<empty first line>\n2026-... [INFO] hello` | `2026-... [INFO] hello\n` |

If you have tooling that parses the old format, update it to expect a
trailing newline on every line and no leading blank line at the top of the
file.

#### 5. `PDOLogger::__destruct` removed

The 1.0.x destructor set `$this->pdo = null`. This had no effect on the
external PDO reference and is removed. If you relied on this for any reason
(closing connections, …), do it explicitly in your application code.

#### 6. `\Psr\Log\InvalidArgumentException` for unknown levels

```php
// 1.0.x — InvalidArgumentException could be either SPL or PSR variant
try { $logger->log('verbose', 'x'); } catch (\InvalidArgumentException $e) {}

// 2.0.0 — always the PSR variant
try { $logger->log('verbose', 'x'); } catch (\Psr\Log\InvalidArgumentException $e) {}
```

The PSR class extends SPL `InvalidArgumentException`, so existing
`catch (\InvalidArgumentException $e)` blocks still catch it.

#### 7. `Logger` is now `final`

Sub-classing `Logger` was never documented, but it is no longer possible. If
you need to customise fan-out behaviour, compose with `LoggerInterface`
instead — see [Multi-Logger](Multi-Logger.md).

### What you do NOT need to change

The constructor signatures stayed exactly the same:

```php
new FileLogger(['path' => '...']);
new PDOLogger(['pdo' => $pdo, 'table' => 'logs']);
new Logger($a, $b, $c);
```

All eight PSR-3 helpers (`emergency()`, `alert()`, …, `debug()`) and the
generic `log()` method work the same way.

### Diff-friendly upgrade checklist

- [ ] `composer.json` — bump the PHP requirement if needed (`>=8.0` for 2.0.0; `^8.2` for the current `main`).
- [ ] Resolve any transitive `psr/log` v1 conflicts (`composer why psr/log`).
- [ ] Remove any code that called undocumented dynamic methods on `Logger`.
- [ ] Replace any `extends Logger` with composition (`Logger` is `final`).
- [ ] Audit `new FileLogger([...])` and `new PDOLogger([...])` call sites
      for missing/invalid options.
- [ ] Audit any tooling that parses log files for the old leading-newline
      format.
- [ ] Optionally tighten `catch (\InvalidArgumentException $e)` blocks
      around `log()` calls to `catch (\Psr\Log\InvalidArgumentException $e)`.

### Rollback

If you need to roll back to 1.0.x temporarily:

```bash
composer require "initphp/logger:^1.0"
```

…but be aware that 1.0.x is no longer maintained: bug fixes and security
updates land only on the current major.

## Future versions

Breaking changes are announced on the package's [GitHub Releases](https://github.com/InitPHP/Logger/releases)
page with a dedicated migration section in this wiki.
