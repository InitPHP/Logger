# Testing Code That Uses the Logger

If your services accept `Psr\Log\LoggerInterface` (the recommended pattern),
testing the logging behaviour is mostly a matter of plugging in a different
implementation.

## Use `NullLogger` when you do not care

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

`NullLogger` ships with `psr/log` itself; no extra dependency needed.

## Use an in-memory `ArrayLogger` when you do

When the assertion is "we logged the right thing", capture the records:

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
    $logger = new ArrayLogger();
    $service = new PaymentService($logger);

    $service->charge(7, 100);

    $this->assertCount(1, $logger->records);
    $this->assertSame('WARNING', $logger->records[0]['level']);
    $this->assertSame('user 7 balance is below threshold', $logger->records[0]['message']);
    $this->assertSame(['user_id' => 7], $logger->records[0]['context']);
}
```

## PHPUnit mocks when you only care about one call

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

Note that mocking the `log()` method covers all calls — `warning('x')` is
internally `log(LogLevel::WARNING, 'x', [])` via `AbstractLogger`.

## Testing `FileLogger` directly

For integration tests that actually exercise file IO, write to a temporary
directory and clean up afterwards:

```php
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
        // recursive rmdir — see the package's own test suite for a helper
    }

    public function test_writes_a_single_line(): void
    {
        $logger = new \InitPHP\Logger\FileLogger(['path' => $this->dir . '/app.log']);

        $logger->info('hi');

        $this->assertStringContainsString('[INFO] hi', file_get_contents($this->dir . '/app.log'));
    }
}
```

## Testing `PDOLogger` directly

In-memory SQLite is ideal:

```php
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
        $logger = new \InitPHP\Logger\PDOLogger(['pdo' => $this->pdo, 'table' => 'logs']);

        $logger->error('boom');

        $row = $this->pdo->query('SELECT * FROM logs')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('ERROR', $row['level']);
        $this->assertSame('boom', $row['message']);
    }
}
```

You will find the same patterns, with more edge cases, in this package's own
[`tests/`](../tests) directory.
