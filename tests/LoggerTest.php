<?php

declare(strict_types=1);

namespace InitPHP\Logger\Tests;

use InitPHP\Logger\Logger;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use TypeError;

final class LoggerTest extends TestCase
{
    public function testConstructorThrowsWhenNoLoggersGiven(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one Psr\\Log\\LoggerInterface');

        new Logger();
    }

    public function testConstructorRejectsNonLoggerInterfaceArguments(): void
    {
        $this->expectException(TypeError::class);

        /** @phpstan-ignore-next-line - intentional bad type */
        new Logger(new \stdClass());
    }

    public function testLogIsFanOutToEveryInnerLogger(): void
    {
        $a = $this->createMock(LoggerInterface::class);
        $b = $this->createMock(LoggerInterface::class);

        $a->expects($this->once())
            ->method('log')
            ->with(LogLevel::ERROR, 'oops', ['k' => 'v']);
        $b->expects($this->once())
            ->method('log')
            ->with(LogLevel::ERROR, 'oops', ['k' => 'v']);

        $logger = new Logger($a, $b);
        $logger->log(LogLevel::ERROR, 'oops', ['k' => 'v']);
    }

    public function testLevelHelpersFanOutThroughLogMethod(): void
    {
        $inner = $this->createMock(LoggerInterface::class);
        $inner->expects($this->once())
            ->method('log')
            ->with(LogLevel::WARNING, 'hi', []);

        $logger = new Logger($inner);
        $logger->warning('hi');
    }

    public function testImplementsPsrLoggerInterface(): void
    {
        $logger = new Logger(new NullLogger());
        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testGetLoggersReturnsInjectedInstancesInOrder(): void
    {
        $a = new NullLogger();
        $b = new NullLogger();

        $logger = new Logger($a, $b);

        $this->assertSame([$a, $b], $logger->getLoggers());
    }

    public function testInnerLoggerExceptionIsPropagated(): void
    {
        $inner = $this->createMock(LoggerInterface::class);
        $inner->method('log')->willThrowException(new \RuntimeException('boom'));

        $logger = new Logger($inner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $logger->info('hi');
    }
}
