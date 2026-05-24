# Recipes

A collection of small, copy-pasteable patterns for common production needs.

## Daily rotation

The `path` tokens in `FileLogger` are resolved **once**, at construction time.
For true daily rotation in a long-running process, rebuild the logger on each
day boundary — or, more commonly, let an external tool such as `logrotate`
handle rotation while you keep the path static.

### Per-request daily files (PHP-FPM, CLI scripts)

If your PHP process is short-lived (the typical web request, or a one-shot
CLI command), tokens give you free daily rotation:

```php
$logger = new FileLogger([
    'path' => '/var/log/app/{year}/{month}/{day}.log',
]);
```

The directory is created automatically on the first write of each day.

### Long-running workers

For workers (queue consumers, daemons), prefer external rotation:

```
/etc/logrotate.d/myapp
---------------------
/var/log/app/app.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
```

`copytruncate` lets `logrotate` rotate the file without signalling the PHP
process, which keeps things simple.

## Logging only above a threshold

PSR-3 itself does not include a level filter, but composition is enough.

```php
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class MinLevelLogger extends AbstractLogger
{
    private const SEVERITY = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT     => 1,
        LogLevel::CRITICAL  => 2,
        LogLevel::ERROR     => 3,
        LogLevel::WARNING   => 4,
        LogLevel::NOTICE    => 5,
        LogLevel::INFO      => 6,
        LogLevel::DEBUG     => 7,
    ];

    public function __construct(
        private LoggerInterface $inner,
        private string $minLevel = LogLevel::WARNING,
    ) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $current = self::SEVERITY[strtolower((string) $level)] ?? null;
        $min     = self::SEVERITY[strtolower($this->minLevel)] ?? null;
        if ($current === null || $min === null || $current > $min) {
            return;
        }
        $this->inner->log($level, $message, $context);
    }
}
```

Now wire it into the multiplexer:

```php
use InitPHP\Logger\FileLogger;
use InitPHP\Logger\Logger;

$logger = new Logger(
    new MinLevelLogger(
        new FileLogger(['path' => '/var/log/app/audit.log']),
        LogLevel::ERROR
    )
);
```

## Two files: one for everything, one for errors only

```php
use InitPHP\Logger\FileLogger;
use InitPHP\Logger\Logger;
use Psr\Log\LogLevel;

$logger = new Logger(
    new FileLogger(['path' => '/var/log/app/app.log']),
    new MinLevelLogger(
        new FileLogger(['path' => '/var/log/app/errors.log']),
        LogLevel::ERROR
    )
);
```

## Database logger that survives outages

By itself, `PDOLogger::log()` lets `PDOException` propagate — a database
outage will abort your application's log call. Wrap it to swallow failures and
fall back to `error_log()`:

```php
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class TolerantLogger extends AbstractLogger
{
    public function __construct(private LoggerInterface $inner) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        try {
            $this->inner->log($level, $message, $context);
        } catch (\Throwable $e) {
            error_log(sprintf(
                'TolerantLogger: %s (level=%s, msg=%s)',
                $e->getMessage(),
                (string) $level,
                (string) $message
            ));
        }
    }
}
```

Then:

```php
$logger = new Logger(
    new FileLogger(['path' => '/var/log/app/app.log']),
    new TolerantLogger(new PDOLogger(['pdo' => $pdo, 'table' => 'logs']))
);
```

The file write is unaffected by database problems.

## Framework integration

### Symfony / Laravel service container

Both frameworks already accept `Psr\Log\LoggerInterface` as a type hint in
their service definitions. Register `InitPHP\Logger\Logger` as the service:

```yaml
# Symfony
services:
    Psr\Log\LoggerInterface:
        class: InitPHP\Logger\Logger
        arguments:
            - '@app.logger.file'
            - '@app.logger.pdo'

    app.logger.file:
        class: InitPHP\Logger\FileLogger
        arguments:
            - { path: '%kernel.logs_dir%/app.log' }

    app.logger.pdo:
        class: InitPHP\Logger\PDOLogger
        arguments:
            - { pdo: '@PDO', table: 'logs' }
```

```php
// Laravel
$this->app->singleton(\Psr\Log\LoggerInterface::class, function ($app) {
    return new \InitPHP\Logger\Logger(
        new \InitPHP\Logger\FileLogger(['path' => storage_path('logs/app.log')]),
        new \InitPHP\Logger\PDOLogger([
            'pdo'   => \DB::connection()->getPdo(),
            'table' => 'logs',
        ]),
    );
});
```

Application code simply injects `LoggerInterface`.

## Timezone control

`FileLogger` and `PDOLogger` produce timestamps with `DateTimeImmutable('now')`,
which respects the PHP-wide timezone. Set it explicitly at boot:

```php
date_default_timezone_set('Europe/Istanbul');
```

If different handlers need different timezones, wrap each one in a small
adapter that converts the timestamp post hoc — or render timestamps into the
message and let your storage tier (PostgreSQL, Elasticsearch…) do the
timezone conversion.
