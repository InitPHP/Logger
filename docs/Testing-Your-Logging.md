# Testing Your Logging

If your services accept `Psr\Log\LoggerInterface` (the recommended pattern),
testing the logging behaviour is mostly a matter of swapping the
implementation. This page collects the patterns that match how the package
itself is tested.

## When you do not care: `NullLogger`

For tests that exercise business logic but do not assert on log output, the
standard `Psr\Log\NullLogger` is plenty:

```php
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PaymentServiceTest extends TestCase
{
    public function test_it_charges_the_user(): void
    {
        $service = new PaymentService(new NullLogger());

        $service->charge(7, 100);

        $this->assertSame(100, $service->totalCharged());
    }
}
```

`NullLogger` ships with `psr/log` itself; no extra dependency.

## When you do care: in-memory `ArrayLogger`

When the assertion is "we logged the right thing", capture the records in
memory.

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

```php
public function test_it_logs_a_warning_when_balance_is_low(): void
{
    $logger  = new ArrayLogger();
    $service = new PaymentService($logger);

    $service->charge(7, 100);

    $this->assertCount(1, $logger->records);
    $this->assertSame('WARNING', $logger->records[0]['level']);
    $this->assertSame('user 7 balance is below threshold', $logger->records[0]['message']);
    $this->assertSame(['user_id' => 7], $logger->records[0]['context']);
}
```

The `HelperTrait` is marked `@internal` so its API may shift between
patch releases — but it is shipped in `src/` and covered by tests like the
rest of the package.

## PHPUnit mocks for single-call assertions

If you just want to assert that a specific call happened:

```php
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class PaymentServiceTest extends TestCase
{
    public function test_it_logs_a_warning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('log')
            ->with(LogLevel::WARNING, $this->stringContains('balance is below threshold'));

        $service = new PaymentService($logger);
        $service->charge(7, 100);
    }
}
```

Mocking the **single** `log()` method covers all calls: `warning('x')` is
internally `log(LogLevel::WARNING, 'x', [])` via `AbstractLogger`. You do
not need to mock all eight helpers.

If you want to be strict about ordering and arguments:

```php
$logger = $this->createMock(LoggerInterface::class);
$logger->expects($this->exactly(2))
    ->method('log')
    ->withConsecutive(
        [LogLevel::INFO, 'starting'],
        [LogLevel::ERROR, $this->stringContains('failed')]
    );
```

## Integration test: `FileLogger` against a temp directory

For tests that exercise real file I/O:

```php
use InitPHP\Logger\FileLogger;
use PHPUnit\Framework\TestCase;

final class FileLoggerIntegrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/my-app-tests-' . uniqid('', true);
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmRecursive($this->dir);
    }

    public function test_writes_a_single_line(): void
    {
        $logger = new FileLogger(['path' => $this->dir . '/app.log']);

        $logger->info('hi');

        $contents = (string) file_get_contents($this->dir . '/app.log');
        $this->assertStringContainsString('[INFO] hi', $contents);
        $this->assertStringEndsWith(PHP_EOL, $contents);
    }

    private function rmRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
```

## Integration test: `PDOLogger` against in-memory SQLite

```php
use InitPHP\Logger\PDOLogger;
use PDO;
use PHPUnit\Framework\TestCase;

final class PDOLoggerIntegrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE logs (level TEXT, message TEXT, date TEXT)');
    }

    public function test_inserts_one_row_per_log_call(): void
    {
        $logger = new PDOLogger(['pdo' => $this->pdo, 'table' => 'logs']);

        $logger->error('boom');

        $row = $this->pdo->query('SELECT * FROM logs')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('ERROR', $row['level']);
        $this->assertSame('boom', $row['message']);
    }
}
```

SQLite's `::memory:` driver is the fastest way to test SQL behaviour without
provisioning a real database. Be aware that some SQL features (server-side
prepared statements, ENUM types) differ from MySQL/PostgreSQL — only test
package interactions against it, not your application's own schema-specific
behaviour.

## Capturing log output during functional tests

In a functional test that drives an entire HTTP request, you typically do
**not** want a real `FileLogger` or `PDOLogger` writing to your dev
environment. Bind the test container's `LoggerInterface` to an `ArrayLogger`
or `NullLogger` instead:

```php
// Symfony — config/services_test.yaml
services:
    Psr\Log\LoggerInterface:
        class: ArrayLogger
        public: true
```

```php
// Symfony test
$logger = self::getContainer()->get(\Psr\Log\LoggerInterface::class);
self::assertCount(0, $logger->records);

$client->request('GET', '/healthz');

self::assertGreaterThan(0, count($logger->records));
```

## Asserting placeholder rendering

The `interpolate()` step is value-level behaviour: assert against the
final, expanded message:

```php
$service->run(['user' => 'jane']);

$this->assertSame(
    'User jane started a session',
    $logger->records[0]['message']
);
```

Avoid asserting on the raw `$message` template plus the `$context` array —
that couples your test to implementation details of how the service
constructs the call.

The package'"'"'s own [`tests/`](https://github.com/InitPHP/Logger/tree/main/tests)
directory uses the same patterns, with more edge cases.

## Related

- [Custom Handlers](Custom-Handlers.md) — extends the `ArrayLogger` idea to
  other test doubles.
- [PSR-3 Compliance](PSR-3-Compliance.md) — why `LoggerInterface` is the
  thing your services should type-hint.
- [API Reference › HelperTrait](API-Reference.md#helpertrait) — the `interpolate`,
  `logLevelVerify`, `getDate` helpers used by `ArrayLogger`.
