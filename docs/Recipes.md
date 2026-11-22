# Recipes

A cookbook of copy-pasteable patterns for common production needs. Every
snippet below assumes `vendor/autoload.php` is already required and that
the `use` statements at the top of your file mention the relevant classes.

## Daily rotation

The `path` tokens in `FileLogger` are resolved **once**, at construction
time. There are two clean strategies for daily rotation, depending on how
long-lived your PHP process is.

### Short-lived processes — PHP-FPM, CLI scripts

If a new `FileLogger` is instantiated per request (the typical web app)
or per script invocation, tokens give you free daily rotation:

```php
$logger = new FileLogger([
    'path' => '/var/log/app/{year}/{month}/{day}.log',
]);
```

A different file every calendar day, every month directory created on
demand, every year directory the same. The package's auto-`mkdir` means
you do not need any deployment script to pre-create them.

### Long-lived processes — workers, daemons

For workers (queue consumers, cron daemons, long-running CLI tools), token
expansion happens once at startup. Two options:

**Option A — restart the logger on day boundaries.**

```php
$day = date('Y-m-d');
$logger = makeLogger();

while (true) {
    $today = date('Y-m-d');
    if ($today !== $day) {
        $logger = makeLogger();   // re-construct, re-expands tokens
        $day    = $today;
    }
    processOneJob($logger);
}

function makeLogger(): \Psr\Log\LoggerInterface
{
    return new FileLogger([
        'path' => '/var/log/app/{year}-{month}-{day}.log',
    ]);
}
```

**Option B — keep a static path, let `logrotate` handle rotation.**

```
/etc/logrotate.d/myapp
─────────────────────
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
process, which keeps things simple and avoids stale file handles.

## Logging only above a threshold

PSR-3 itself does not define a level filter, but composition handles it
cleanly. A `MinLevelLogger` decorator:

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

Use it like any other PSR-3 handler:

```php
$logger = new MinLevelLogger(
    new FileLogger(['path' => '/var/log/app/audit.log']),
    LogLevel::ERROR
);

$logger->debug('chatty');     // dropped
$logger->info('also chatty'); // dropped
$logger->error('boom');       // recorded
```

## Two files: everything + errors-only

A classic operations layout:

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

$logger->info('only in app.log');
$logger->error('in app.log AND errors.log');
```

## Database logger that survives outages

By itself, `PDOLogger::log()` lets `\PDOException` propagate — a database
outage will abort your application's log call (and the rest of any fan-out
chain). Wrap it:

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

$logger = new Logger(
    new FileLogger(['path' => '/var/log/app/app.log']),
    new TolerantLogger(new PDOLogger(['pdo' => $pdo, 'table' => 'logs']))
);
```

The file log is now unaffected by database problems, but PDO failures still
end up in the SAPI error log via `error_log()`.

## Attaching shared context (request id, user id…)

A wrapper that merges fixed context into every call:

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
        // Per-call context wins on key collisions.
        $this->inner->log($level, $message, $this->extra + $context);
    }
}
```

```php
$logger = new WithContext(
    new Logger(
        new FileLogger(['path' => '/var/log/app/app.log']),
        new PDOLogger(['pdo' => $pdo, 'table' => 'logs'])
    ),
    ['request_id' => $requestId, 'user_id' => $currentUser?->id()]
);

$logger->info('charging user {user_id}');
// {user_id} → from $extra
// → both handlers see request_id and user_id
```

## Symfony service container

```yaml
# config/services.yaml
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

Application code now type-hints `Psr\Log\LoggerInterface` and gets the
multiplexer.

## Laravel service container

```php
// app/Providers/AppServiceProvider.php

public function register(): void
{
    $this->app->singleton(\Psr\Log\LoggerInterface::class, function ($app) {
        return new \InitPHP\Logger\Logger(
            new \InitPHP\Logger\FileLogger([
                'path' => storage_path('logs/app-{year}-{month}-{day}.log'),
            ]),
            new \InitPHP\Logger\PDOLogger([
                'pdo'   => \DB::connection()->getPdo(),
                'table' => 'logs',
            ]),
        );
    });
}
```

Now any constructor or method that type-hints `LoggerInterface` resolves
to the multiplexer.

## Timezone control

`FileLogger` and `PDOLogger` produce timestamps with
`DateTimeImmutable('now')`, which respects the PHP-wide timezone. Set it
explicitly at boot:

```php
date_default_timezone_set('Europe/Istanbul');
```

If different handlers must live in different timezones, write a small
adapter that converts the timestamp post hoc; or — preferred — render
timestamps without a fixed timezone and let your storage tier
(PostgreSQL, Elasticsearch…) do the conversion at query time.

## Always-on debug logger in production

In production it is occasionally useful to log `debug` for a single
suspicious user without raising verbosity for everyone. The
`WithContext` + `MinLevelLogger` combo handles it:

```php
$verbose = $isSuspicious ? LogLevel::DEBUG : LogLevel::INFO;

$logger = new MinLevelLogger(
    new Logger(
        new FileLogger(['path' => '/var/log/app/app.log']),
        new PDOLogger(['pdo' => $pdo, 'table' => 'logs']),
    ),
    $verbose
);
```

## Related

- [Custom Handlers](Custom-Handlers.md) — `MinLevelLogger`, `TolerantLogger`,
  `WithContext` are all custom handlers in disguise.
- [Testing Your Logging](Testing-Your-Logging.md) — patterns specifically for
  test suites.
- [Multi-Logger](Multi-Logger.md) — ordering and fault isolation in the fan-out.
- [Error Handling](Error-Handling.md) — what exceptions to expect and when.
