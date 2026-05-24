<?php

declare(strict_types=1);

namespace InitPHP\Logger;

use InvalidArgumentException;
use Psr\Log\AbstractLogger;
use Stringable;

use function date;
use function dirname;
use function error_log;
use function file_put_contents;
use function is_dir;
use function is_string;
use function mkdir;
use function sprintf;
use function strtoupper;

use const FILE_APPEND;
use const LOCK_EX;
use const PHP_EOL;

/**
 * PSR-3 logger that appends each record as a single line to a file.
 *
 * Each emitted line follows the format:
 * `<ISO-8601 timestamp> [<UPPERCASE-LEVEL>] <interpolated message>` + PHP_EOL.
 *
 * The destination path supports the following tokens, expanded once at
 * construction time using the current process clock:
 *   `{year}`, `{month}`, `{day}`, `{hour}`, `{minute}`, `{second}`
 *
 * Use cases for tokens include daily rotation (e.g. `/var/log/app-{year}-{month}-{day}.log`).
 *
 * Writes are appended with `FILE_APPEND | LOCK_EX` so concurrent processes do not
 * interleave bytes within a single record. If the parent directory does not exist,
 * it is created recursively (mode `0775`). Write failures are reported through
 * {@see error_log()} but never thrown — PSR-3 method calls must not interrupt
 * application flow.
 */
class FileLogger extends AbstractLogger
{
    use HelperTrait;

    /**
     * Resolved, token-interpolated absolute or relative path to the log file.
     */
    protected string $path;

    /**
     * @param array{path?: string} $options Required `path`: destination file path. May contain
     *                                      `{year}/{month}/{day}/{hour}/{minute}/{second}` tokens.
     *
     * @throws InvalidArgumentException If `path` is missing, not a string, or empty.
     */
    public function __construct(array $options = [])
    {
        if (!isset($options['path']) || !is_string($options['path']) || $options['path'] === '') {
            throw new InvalidArgumentException(
                'FileLogger requires a non-empty string "path" option.'
            );
        }

        $this->path = $this->interpolate($options['path'], [
            'year'   => date('Y'),
            'month'  => date('m'),
            'day'    => date('d'),
            'hour'   => date('H'),
            'minute' => date('i'),
            'second' => date('s'),
        ]);
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

        $line = $this->getDate('c')
            . ' [' . strtoupper((string) $level) . '] '
            . $this->interpolate($message, $context)
            . PHP_EOL;

        $this->ensureDirectoryExists();

        $bytes = @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
        if ($bytes === false) {
            error_log(sprintf('InitPHP\\Logger\\FileLogger: failed to write log entry to "%s".', $this->path));
        }
    }

    /**
     * Returns the resolved log file path (after token interpolation).
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Create the parent directory of {@see $path} if it does not exist.
     *
     * Failures are reported via `error_log()` without throwing; the subsequent
     * `file_put_contents()` call will surface the same condition.
     */
    private function ensureDirectoryExists(): void
    {
        $dir = dirname($this->path);
        if ($dir === '' || $dir === '.' || is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            error_log(sprintf('InitPHP\\Logger\\FileLogger: failed to create directory "%s".', $dir));
        }
    }
}
