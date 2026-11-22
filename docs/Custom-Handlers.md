# Custom Handlers

`InitPHP\Logger\Logger` accepts **any** `Psr\Log\LoggerInterface`. Adding new
backends — syslog, Slack, an in-memory ring buffer for tests, a third-party
SaaS — is simply a matter of writing a class that implements that contract.

The path of least resistance is to extend `Psr\Log\AbstractLogger`: it
provides default implementations of `emergency()` through `debug()` that all
delegate to a single `log()` method. You only implement `log()`.

## Minimal example: syslog

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

Wire it into the multiplexer alongside the built-in handlers:

```php
use InitPHP\Logger\FileLogger;
use InitPHP\Logger\Logger;

$logger = new Logger(
    new FileLogger(['path' => __DIR__ . '/logs/app.log']),
    new SyslogHandler('myapp', LOG_USER)
);
```

## In-memory `ArrayLogger` — perfect for tests

Capturing log records in memory makes assertions trivial.

```php
use InitPHP\Logger\HelperTrait;
use Psr\Log\AbstractLogger;

final class ArrayLogger extends AbstractLogger
{
    use HelperTrait;

    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>, date: string}>
     */
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

`ArrayLogger` reuses the package's own `HelperTrait` for context
interpolation and level validation. The trait is marked `@internal`, so
treat it as an implementation detail — but it is shipped in `src/` and
covered by tests like the rest of the package. See
[Testing Your Logging](Testing-Your-Logging.md) for usage examples.

## Slack / HTTP webhook example

```php
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class SlackHandler extends AbstractLogger
{
    private const NOTIFY = [
        LogLevel::EMERGENCY => true,
        LogLevel::ALERT     => true,
        LogLevel::CRITICAL  => true,
        LogLevel::ERROR     => true,
    ];

    public function __construct(private string $webhookUrl) {}

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $key = strtolower((string) $level);
        if (!($this::NOTIFY[$key] ?? false)) {
            return; // Slack only cares about high-severity events.
        }

        $payload = json_encode([
            'text' => sprintf('[%s] %s', strtoupper((string) $level), (string) $message),
        ], JSON_THROW_ON_ERROR);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($this->webhookUrl, false, $ctx);
        // Network failures swallowed on purpose — see "Guidelines" below.
    }
}
```

## Guidelines for handler authors

A handful of rules to keep your handler well-behaved as a PSR-3 citizen.

### 1. Implement `LoggerInterface`

Either directly, or — preferably — by extending
`\Psr\Log\AbstractLogger`. Anything else will not fit into
`InitPHP\Logger\Logger`.

### 2. Do not throw for ordinary backend failures

PSR-3 §1.1 explicitly permits one exception type from `log()`:
`\Psr\Log\InvalidArgumentException`, for unknown levels. Everything else —
network errors, full disks, database outages, JSON encoding failures — must
either be **swallowed** (with an `error_log()` notice) or routed to a
fallback handler. A logger that throws on every call interrupts business
logic that is supposed to be observing failures, not creating them.

### 3. Render `\Throwable` context values

Users expect `{exception}` to produce something readable. Either reuse the
package's `HelperTrait::interpolate()` or special-case
`instanceof \Throwable` yourself.

### 4. Accept `string|\Stringable` messages

PSR-3 v3 requires it. Copy the `log()` signature exactly:

```php
public function log($level, string|\Stringable $message, array $context = []): void
```

PHP's type system will enforce the second parameter for you.

### 5. Make construction validate eagerly

A misconfigured handler should fail at boot, not on the first log call —
matching the behaviour of the built-in handlers. Use `\InvalidArgumentException`
from the constructor and a clear, actionable error message:

```php
throw new \InvalidArgumentException(
    'SyslogHandler "ident" option must be a non-empty string.'
);
```

### 6. Keep `log()` cheap

Handlers run on every call. Avoid expensive work in the hot path: cache
prepared statements, lazy-open files, batch network calls when the payload
allows.

## Related

- [Multi-Logger](Multi-Logger.md) — composing custom handlers with the built-ins.
- [PSR-3 Compliance](PSR-3-Compliance.md) — what the contract demands.
- [Context Interpolation](Context-Interpolation.md) — placeholder rules to
  honour in your own handler.
- [Testing Your Logging](Testing-Your-Logging.md) — `ArrayLogger`-style patterns.
