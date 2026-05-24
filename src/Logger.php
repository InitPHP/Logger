<?php

declare(strict_types=1);

namespace InitPHP\Logger;

use InvalidArgumentException;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

use function array_values;

/**
 * Fan-out multiplexer: forwards every PSR-3 call to a fixed set of inner loggers.
 *
 * Constructed with one or more {@see LoggerInterface} instances. Each `log()`
 * (and, via {@see AbstractLogger}, each `emergency()`/`alert()`/.../`debug()`)
 * call is dispatched to every inner logger in the order they were supplied.
 *
 * Exceptions thrown by an inner logger are *not* caught: PSR-3 requires
 * `\Psr\Log\InvalidArgumentException` for unknown levels and otherwise mandates
 * silent handling. Callers that need fault-tolerance across handlers should
 * wrap individual handlers themselves.
 */
final class Logger extends AbstractLogger
{
    /** @var list<LoggerInterface> */
    private array $loggers;

    /**
     * @throws InvalidArgumentException If no logger is supplied.
     */
    public function __construct(LoggerInterface ...$loggers)
    {
        if ($loggers === []) {
            throw new InvalidArgumentException(
                'InitPHP\\Logger\\Logger requires at least one Psr\\Log\\LoggerInterface instance.'
            );
        }
        $this->loggers = array_values($loggers);
    }

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        foreach ($this->loggers as $logger) {
            $logger->log($level, $message, $context);
        }
    }

    /**
     * Returns the inner loggers in the order they were registered.
     *
     * @return list<LoggerInterface>
     */
    public function getLoggers(): array
    {
        return $this->loggers;
    }
}
