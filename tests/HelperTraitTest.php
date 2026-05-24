<?php

declare(strict_types=1);

namespace InitPHP\Logger\Tests;

use InitPHP\Logger\HelperTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

final class HelperTraitTest extends TestCase
{
    /**
     * @return object{
     *     interpolate: \Closure,
     *     verify:      \Closure,
     *     date:        \Closure,
     * }
     */
    private function helper(): object
    {
        $instance = new class () {
            use HelperTrait {
                interpolate as public publicInterpolate;
                logLevelVerify as public publicVerify;
                getDate as public publicGetDate;
            }
        };

        return (object) [
            'interpolate' => \Closure::fromCallable([$instance, 'publicInterpolate']),
            'verify'      => \Closure::fromCallable([$instance, 'publicVerify']),
            'date'        => \Closure::fromCallable([$instance, 'publicGetDate']),
        ];
    }

    public function testInterpolateReturnsMessageUnchangedWhenContextEmpty(): void
    {
        $h = $this->helper();
        $this->assertSame('no replace', ($h->interpolate)('no replace', []));
    }

    public function testInterpolateReplacesNullBoolScalarStringable(): void
    {
        $h = $this->helper();

        $stringable = new class () implements \Stringable {
            public function __toString(): string
            {
                return 'S';
            }
        };

        $result = ($h->interpolate)(
            'a={a} b={b} c={c} d={d} e={e} f={f}',
            [
                'a' => null,
                'b' => true,
                'c' => false,
                'd' => 'hi',
                'e' => 42,
                'f' => $stringable,
            ]
        );

        $this->assertSame('a= b=true c=false d=hi e=42 f=S', $result);
    }

    public function testInterpolateLeavesUnsupportedTypesUntouched(): void
    {
        $h = $this->helper();

        $result = ($h->interpolate)('arr={arr} obj={obj}', [
            'arr' => ['x', 'y'],
            'obj' => new \stdClass(),
        ]);

        $this->assertSame('arr={arr} obj={obj}', $result);
    }

    public function testInterpolateRendersThrowable(): void
    {
        $h = $this->helper();
        $exception = new \LogicException('bad state', 7);

        $result = ($h->interpolate)('{exception}', ['exception' => $exception]);

        $this->assertStringStartsWith('LogicException(7): bad state in ', $result);
    }

    public function testInterpolateAcceptsStringableMessage(): void
    {
        $h = $this->helper();
        $msg = new class () implements \Stringable {
            public function __toString(): string
            {
                return 'hello {name}';
            }
        };

        $this->assertSame('hello world', ($h->interpolate)($msg, ['name' => 'world']));
    }

    public function testInterpolateIgnoresNonStringKeys(): void
    {
        $h = $this->helper();

        $result = ($h->interpolate)('a={0} b={b}', [0 => 'X', 'b' => 'Y']);

        $this->assertSame('a={0} b=Y', $result);
    }

    public function testGetDateProducesIso8601ByDefault(): void
    {
        $h = $this->helper();
        $value = ($h->date)();
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
            $value
        );
    }

    public function testGetDateRespectsCustomFormat(): void
    {
        $h = $this->helper();
        $value = ($h->date)('Y-m-d');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    public function testLogLevelVerifyAcceptsAllEightPsr3Levels(): void
    {
        $h = $this->helper();

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
            ($h->verify)($level);
        }
        $this->expectNotToPerformAssertions();
    }

    public function testLogLevelVerifyIsCaseInsensitive(): void
    {
        $h = $this->helper();
        ($h->verify)('ERROR');
        ($h->verify)('Warning');
        $this->expectNotToPerformAssertions();
    }

    public function testLogLevelVerifyRejectsUnknownString(): void
    {
        $h = $this->helper();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown log level "verbose"');

        ($h->verify)('verbose');
    }

    public function testLogLevelVerifyRejectsNonStringValues(): void
    {
        $h = $this->helper();

        $this->expectException(InvalidArgumentException::class);

        ($h->verify)(123);
    }
}
