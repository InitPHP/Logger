# Writing a Custom Handler

`InitPHP\Logger\Logger` accepts any `Psr\Log\LoggerInterface`, so adding new
backends (syslog, Slack, an in-memory ring buffer for tests, …) is simply a
matter of writing a class that implements that contract.

The path of least resistance is to extend `Psr\Log\AbstractLogger`: it provides
default implementations of `emergency()` through `debug()` that delegate to a
single `log()` method, so you only implement `log()`.

## Minimal example: a syslog handler

```php
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

final class SyslogHandler extends AbstractLogger
{
    private const PRIORITY = [
        LogLevel::EMERGENCY => LOG_EMERG,
        LogLevel::ALERT     => LOG_ALERT,
        LogLevel::CRITICAL  => LOG_CRIT,
        LogLevel::ERROR     => LOG_ERR,
        LogLevel::WARNING   => LOG_WARNING,
        LogLevel::NOTICE    => LOG_NOTICE,
        LogLevel::INFO      => LOG_INFO,
        LogLevel::DEBUG     => LOG_DEBUG,
    ];

    public function __construct(string $ident = 'app', int $facility = LOG_USER)
    {
        openlog($ident, LOG_PID | LOG_ODELAY, $facility);
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $normalised = strtolower((string) $level);

        if (!isset(self::PRIORITY[$normalised])) {
            throw new InvalidArgumentException(sprintf('Unknown log level "%s".', $level));
        }

        syslog(self::PRIORITY[$normalised], $this->interpolate((string) $message, $context));
    }

    private function interpolate(string $message, array $context): string
    {
        if ($context === []) {
            return $message;
        }
        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof \Stringable) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }
        return strtr($message, $replace);
    }
}
```

Plug it into the multiplexer alongside the built-in handlers:

```php
use InitPHP\Logger\FileLogger;
use InitPHP\Logger\Logger;

$logger = new Logger(
    new FileLogger(['path' => __DIR__ . '/logs/app.log']),
    new SyslogHandler('myapp', LOG_USER)
);
```

## Sharing the placeholder logic

If your handler needs the same interpolation rules as the built-in handlers
(null/bool/Throwable handling, identical `{key}` syntax), you can `use` the
internal `HelperTrait`. It is marked `@internal`, so prefer composition with
your own helper unless you are happy to track the package's behaviour.

```php
use InitPHP\Logger\HelperTrait;
use Psr\Log\AbstractLogger;

final class ArrayLogger extends AbstractLogger
{
    use HelperTrait;

    /** @var list<array{level: string, message: string, context: array<string, mixed>, date: string}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->logLevelVerify($level);

        $this->records[] = [
            'level'   => strtoupper((string) $level),
            'message' => $this->interpolate($message, $context),
            'context' => $context,
            'date'    => $this->getDate('c'),
        ];
    }
}
```

`ArrayLogger` is particularly handy in tests — see
[`08-testing-your-logging.md`](08-testing-your-logging.md).

## Guidelines

A few rules to keep your handler well-behaved as a PSR-3 citizen:

- **Implement `LoggerInterface`** (directly, or by extending `AbstractLogger`).
  Anything else won't fit into `InitPHP\Logger\Logger`.
- **Do not throw for ordinary backend failures.** Use `error_log()` or a
  fallback handler. The only case PSR-3 condones throwing is
  `Psr\Log\InvalidArgumentException` for unknown levels.
- **Render `\Throwable` context values.** Either via the package's
  `HelperTrait`, or by special-casing `instanceof \Throwable` yourself.
- **Accept `string|\Stringable` messages.** PSR-3 v3 requires it; PHP's type
  system will enforce it for you if you copy the `log()` signature exactly.
- **Make construction validate eagerly.** Bad config should fail at boot, not
  on the first log call.
