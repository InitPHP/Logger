<?php

declare(strict_types=1);

namespace InitPHP\Logger\Tests;

use InitPHP\Logger\PDOLogger;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\Log\LogLevel;

final class PDOLoggerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE logs (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                level   TEXT NOT NULL,
                message TEXT NOT NULL,
                date    TEXT NOT NULL
            )
        SQL);
    }

    public function testConstructorThrowsWhenPdoMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PDOLogger requires a "pdo" option.');

        new PDOLogger(['table' => 'logs']);
    }

    public function testConstructorThrowsWhenPdoIsWrongType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PDOLogger "pdo" option must be a PDO instance.');

        /** @phpstan-ignore-next-line */
        new PDOLogger(['pdo' => 'not-a-pdo', 'table' => 'logs']);
    }

    public function testConstructorThrowsWhenTableMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PDOLogger requires a "table" option.');

        new PDOLogger(['pdo' => $this->pdo]);
    }

    public function testConstructorThrowsWhenTableIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty string');

        new PDOLogger(['pdo' => $this->pdo, 'table' => '']);
    }

    public function testConstructorRejectsTableNamesWithIllegalCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid SQL identifier');

        new PDOLogger(['pdo' => $this->pdo, 'table' => 'logs; DROP TABLE users']);
    }

    public function testConstructorRejectsTableNamesStartingWithDigit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PDOLogger(['pdo' => $this->pdo, 'table' => '123logs']);
    }

    public function testConstructorAcceptsValidTableNames(): void
    {
        $logger = new PDOLogger(['pdo' => $this->pdo, 'table' => 'application_logs_v2']);
        $this->assertSame('application_logs_v2', $logger->getTable());
    }

    public function testLogInsertsRowWithUppercaseLevel(): void
    {
        $logger = new PDOLogger(['pdo' => $this->pdo, 'table' => 'logs']);

        $logger->error('something broke');

        $row = $this->fetchOneRow('SELECT level, message, date FROM logs');
        $this->assertSame('ERROR', $row['level']);
        $this->assertSame('something broke', $row['message']);
        $this->assertIsString($row['date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['date']);
    }

    public function testLogInterpolatesContextPlaceholders(): void
    {
        $logger = new PDOLogger(['pdo' => $this->pdo, 'table' => 'logs']);

        $logger->warning('user {id} exceeded {limit} requests', ['id' => 7, 'limit' => 100]);

        $row = $this->fetchOneRow('SELECT message FROM logs');
        $this->assertSame('user 7 exceeded 100 requests', $row['message']);
    }

    public function testUnknownLevelThrowsPsrInvalidArgumentException(): void
    {
        $logger = new PDOLogger(['pdo' => $this->pdo, 'table' => 'logs']);

        $this->expectException(PsrInvalidArgumentException::class);

        $logger->log('verbose', 'no such level');
    }

    public function testAllPsr3LevelsAreAccepted(): void
    {
        $logger = new PDOLogger(['pdo' => $this->pdo, 'table' => 'logs']);

        foreach (
            [
                LogLevel::EMERGENCY,
                LogLevel::ALERT,
                LogLevel::CRITICAL,
                LogLevel::ERROR,
                LogLevel::WARNING,
                LogLevel::NOTICE,
                LogLevel::INFO,
                LogLevel::DEBUG,
            ] as $level
        ) {
            $logger->log($level, 'msg-' . $level);
        }

        $this->assertSame(8, $this->countRows());
    }

    public function testMessageWithSqlMetaCharactersIsNotInterpretedAsSql(): void
    {
        $logger = new PDOLogger(['pdo' => $this->pdo, 'table' => 'logs']);

        $payload = "'; DROP TABLE logs; --";
        $logger->info($payload);

        $row = $this->fetchOneRow('SELECT message FROM logs');
        $this->assertSame($payload, $row['message']);

        // logs table must still exist
        $this->assertSame(1, $this->countRows());
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOneRow(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        return $row;
    }

    private function countRows(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM logs');
        $this->assertInstanceOf(\PDOStatement::class, $stmt);

        return (int) $stmt->fetchColumn();
    }
}
