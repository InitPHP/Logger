<?php

declare(strict_types=1);

namespace InitPHP\Logger;

use DateTimeImmutable;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

use function get_class;
use function implode;
use function in_array;
use function is_bool;
use function is_object;
use function is_scalar;
use function is_string;
use function method_exists;
use function sprintf;
use function strtolower;
use function strtr;

/**
 * Internal helper for the bundled handlers.
 *
 * Provides:
 *  - PSR-3 placeholder interpolation (including `{exception}` Throwable rendering),
 *  - PSR-3 log level validation,
 *  - a consistent timestamp generator.
 *
 * @internal
 */
trait HelperTrait
{
    /**
     * Canonical PSR-3 log levels, in severity order (highest first).
     *
     * @var list<string>
     */
    private array $levels = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    /**
     * Replace `{key}` placeholders in `$message` with values from `$context`.
     *
     * Replacement rules per context value type:
     *   - `null`                    → empty string
     *   - bool                      → "true" / "false"
     *   - scalar (int/float/string) → cast to string
     *   - {@see Stringable}         → cast to string
     *   - {@see Throwable}          → "<Class>(<code>): <message> in <file>:<line>"
     *   - everything else (arrays, non-stringable objects) → placeholder is left untouched
     *
     * Placeholder syntax follows PSR-3 §1.2 (alphanumeric, underscore, dot).
     *
     * @param string|Stringable $message Message containing zero or more `{placeholder}` tokens.
     * @param array<string, mixed> $context Replacement values keyed by placeholder name.
     *
     * @return string The interpolated message.
     */
    protected function interpolate(string|Stringable $message, array $context = []): string
    {
        $message = (string) $message;

        if ($context === []) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $rendered = $this->stringifyContextValue($value);
            if ($rendered === null) {
                continue;
            }
            $replace['{' . $key . '}'] = $rendered;
        }

        return $replace === [] ? $message : strtr($message, $replace);
    }

    /**
     * Generate a formatted timestamp using {@see DateTimeImmutable}.
     *
     * @param string $format Any format accepted by {@see DateTimeImmutable::format()}. Defaults to ISO-8601 (`c`).
     */
    protected function getDate(string $format = 'c'): string
    {
        return (new DateTimeImmutable('now'))->format($format);
    }

    /**
     * Assert that `$level` is one of the eight PSR-3 levels.
     *
     * Comparison is case-insensitive against the canonical lowercase constants.
     *
     * @phpstan-assert string $level
     *
     * @throws InvalidArgumentException When the level is not a recognised PSR-3 level.
     */
    protected function logLevelVerify(mixed $level): void
    {
        if (!is_string($level) || !in_array(strtolower($level), $this->levels, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown log level "%s". Allowed PSR-3 levels are: %s.',
                is_string($level) ? $level : get_debug_type($level),
                implode(', ', $this->levels)
            ));
        }
    }

    /**
     * Render a single context value as a string, or return `null` if the value is not renderable.
     */
    private function stringifyContextValue(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof Throwable) {
            return sprintf(
                '%s(%d): %s in %s:%d',
                get_class($value),
                $value->getCode(),
                $value->getMessage(),
                $value->getFile(),
                $value->getLine()
            );
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return null;
    }
}
