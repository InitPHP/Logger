<?php

declare(strict_types=1);

namespace InitPHP\Logger\Tests;

use InitPHP\Logger\FileLogger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\Log\LogLevel;
use RuntimeException;

final class FileLoggerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/initphp-logger-' . uniqid('', true);
        if (!mkdir($this->tempDir) && !is_dir($this->tempDir)) {
            throw new RuntimeException('Could not create temp dir for tests.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testConstructorThrowsWhenPathMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('FileLogger requires a non-empty string "path" option.');

        new FileLogger([]);
    }

    public function testConstructorThrowsWhenPathIsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FileLogger(['path' => '']);
    }

    public function testConstructorThrowsWhenPathIsNotString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore-next-line - intentional bad type */
        new FileLogger(['path' => 123]);
    }

    public function testLogWritesSingleLineEndingWithNewline(): void
    {
        $path = $this->tempDir . '/app.log';
        $logger = new FileLogger(['path' => $path]);

        $logger->info('hello world');

        $contents = (string) file_get_contents($path);
        $this->assertStringEndsWith(PHP_EOL, $contents);
        $this->assertSame(1, substr_count($contents, PHP_EOL));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2} \[INFO\] hello world\r?\n$/',
            $contents
        );
    }

    public function testFirstLineDoesNotBeginWithBlankLine(): void
    {
        $path = $this->tempDir . '/app.log';
        $logger = new FileLogger(['path' => $path]);

        $logger->error('boom');

        $contents = (string) file_get_contents($path);
        $this->assertSame('2', substr($contents, 0, 1) === '2' ? '2' : substr($contents, 0, 1), 'must start with timestamp digit');
        $this->assertStringStartsNotWith(PHP_EOL, $contents);
    }

    public function testMultipleLogsAppendOnePerLine(): void
    {
        $path = $this->tempDir . '/app.log';
        $logger = new FileLogger(['path' => $path]);

        $logger->info('first');
        $logger->warning('second');
        $logger->error('third');

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('[INFO] first', $lines[0]);
        $this->assertStringContainsString('[WARNING] second', $lines[1]);
        $this->assertStringContainsString('[ERROR] third', $lines[2]);
    }

    public function testContextPlaceholdersAreInterpolated(): void
    {
        $path = $this->tempDir . '/app.log';
        $logger = new FileLogger(['path' => $path]);

        $logger->error('User {username} hit a {kind} error.', ['username' => 'john', 'kind' => 'fatal']);

        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('User john hit a fatal error.', $contents);
    }

    public function testInterpolationHandlesNullBoolAndStringable(): void
    {
        $path = $this->tempDir . '/app.log';
        $logger = new FileLogger(['path' => $path]);

        $stringable = new class () implements \Stringable {
            public function __toString(): string
            {
                return 'STR';
            }
        };

        $logger->info('a={a} b={b} c={c} d={d}', [
            'a' => null,
            'b' => true,
            'c' => false,
            'd' => $stringable,
        ]);

        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('a= b=true c=false d=STR', $contents);
    }

    public function testInterpolationRendersThrowable(): void
    {
        $path = $this->tempDir . '/app.log';
        $logger = new FileLogger(['path' => $path]);

        $exception = new \RuntimeException('disk full', 42);
        $logger->critical('failure: {exception}', ['exception' => $exception]);

        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('RuntimeException(42): disk full in', $contents);
    }

    public function testUnknownLevelThrowsPsrInvalidArgumentException(): void
    {
        $logger = new FileLogger(['path' => $this->tempDir . '/x.log']);

        $this->expectException(PsrInvalidArgumentException::class);

        $logger->log('verbose', 'nope');
    }

    public function testPathTokensAreInterpolatedAtConstruction(): void
    {
        $template = $this->tempDir . '/app-{year}-{month}-{day}.log';
        $logger = new FileLogger(['path' => $template]);

        $expected = $this->tempDir . '/app-' . date('Y') . '-' . date('m') . '-' . date('d') . '.log';
        $this->assertSame($expected, $logger->getPath());
    }

    public function testParentDirectoryIsCreatedAutomatically(): void
    {
        $path = $this->tempDir . '/nested/deep/app.log';
        $logger = new FileLogger(['path' => $path]);

        $logger->info('hi');

        $this->assertFileExists($path);
        $this->assertDirectoryExists($this->tempDir . '/nested/deep');
    }

    public function testAllPsr3LevelsAreAccepted(): void
    {
        $path = $this->tempDir . '/levels.log';
        $logger = new FileLogger(['path' => $path]);

        $logger->emergency('a');
        $logger->alert('b');
        $logger->critical('c');
        $logger->error('d');
        $logger->warning('e');
        $logger->notice('f');
        $logger->info('g');
        $logger->debug('h');

        $contents = (string) file_get_contents($path);
        foreach (['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'] as $needle) {
            $this->assertStringContainsString('[' . $needle . ']', $contents);
        }
    }

    public function testLogLevelConstantsAreAccepted(): void
    {
        $path = $this->tempDir . '/const.log';
        $logger = new FileLogger(['path' => $path]);

        $logger->log(LogLevel::WARNING, 'caution');

        $this->assertStringContainsString('[WARNING] caution', (string) file_get_contents($path));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
