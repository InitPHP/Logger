<?php

declare(strict_types=1);

namespace InitPHP\Logger;

use InvalidArgumentException;
use PDO;
use Psr\Log\AbstractLogger;
use Stringable;

use function is_string;
use function preg_match;
use function sprintf;
use function strtoupper;

/**
 * PSR-3 logger that writes each record as a row in a relational database table.
 *
 * The target table is expected to expose at least the columns `level`, `message`
 * and `date`. A reference MySQL DDL is published in the package README; SQLite
 * and PostgreSQL equivalents live in `docs/03-pdo-logger.md`.
 *
 * Values are bound through prepared statements, so the `message` payload is safe
 * against SQL injection. The table identifier itself is validated against the
 * regular expression `/^[A-Za-z_][A-Za-z0-9_]*$/` at construction time because
 * SQL forbids parameterising identifiers; passing anything outside that grammar
 * raises {@see InvalidArgumentException}.
 */
class PDOLogger extends AbstractLogger
{
    use HelperTrait;

    /** Regex describing identifiers accepted as table names. */
    private const TABLE_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    protected PDO $pdo;

    protected string $table;

    /**
     * @param array{pdo?: PDO, table?: string} $options Required keys:
     *                                                  - `pdo`:   an already-configured {@see PDO} instance.
     *                                                  - `table`: the destination table name, matching {@see TABLE_NAME_PATTERN}.
     *
     * @throws InvalidArgumentException If options are missing, of the wrong type, or the table name is rejected.
     */
    public function __construct(array $options = [])
    {
        if (!isset($options['pdo'])) {
            throw new InvalidArgumentException('PDOLogger requires a "pdo" option.');
        }
        if (!$options['pdo'] instanceof PDO) {
            throw new InvalidArgumentException('PDOLogger "pdo" option must be a PDO instance.');
        }

        if (!isset($options['table'])) {
            throw new InvalidArgumentException('PDOLogger requires a "table" option.');
        }
        if (!is_string($options['table']) || $options['table'] === '') {
            throw new InvalidArgumentException('PDOLogger "table" option must be a non-empty string.');
        }
        if (preg_match(self::TABLE_NAME_PATTERN, $options['table']) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'PDOLogger "table" option "%s" is not a valid SQL identifier; expected %s.',
                $options['table'],
                self::TABLE_NAME_PATTERN
            ));
        }

        $this->pdo = $options['pdo'];
        $this->table = $options['table'];
    }

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     *
     * @throws \Psr\Log\InvalidArgumentException When `$level` is not a PSR-3 level.
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->logLevelVerify($level);

        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (level, message, date) VALUES (?, ?, ?)',
            $this->table
        ));

        $statement->execute([
            strtoupper((string) $level),
            $this->interpolate($message, $context),
            $this->getDate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Returns the configured destination table name.
     */
    public function getTable(): string
    {
        return $this->table;
    }
}
