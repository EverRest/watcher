<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Level;

final class LevelTest extends TestCase
{
    /** @return array<string, array{string, Level}> */
    public static function parseCases(): array
    {
        return [
            'upper debug' => ['DEBUG', Level::Debug],
            'lower info' => ['info', Level::Info],
            'notice' => ['Notice', Level::Notice],
            'warn alias' => ['warn', Level::Warning],
            'warning' => ['WARNING', Level::Warning],
            'err alias' => ['err', Level::Error],
            'error' => ['ERROR', Level::Error],
            'crit alias' => ['crit', Level::Critical],
            'critical' => ['CRITICAL', Level::Critical],
            'alert' => ['alert', Level::Alert],
            'emerg alias' => ['emerg', Level::Emergency],
            'fatal alias' => ['FATAL', Level::Emergency],
            'unknown falls back to info' => ['banana', Level::Info],
            'empty falls back to info' => ['', Level::Info],
        ];
    }

    #[DataProvider('parseCases')]
    public function testParse(string $raw, Level $expected): void
    {
        self::assertSame($expected, Level::parse($raw));
    }

    /** @return array<string, array{string, ?Level}> */
    public static function tryParseCases(): array
    {
        return [
            'trace is debug (no Trace case)' => ['trace', Level::Debug],
            'pino 10 trace' => ['10', Level::Debug],
            'pino 20 debug' => ['20', Level::Debug],
            'pino 30 info' => ['30', Level::Info],
            'pino 40 warn' => ['40', Level::Warning],
            'pino 50 error' => ['50', Level::Error],
            'pino 60 fatal' => ['60', Level::Emergency],
            'panic' => ['panic', Level::Emergency],
            'ok (datadog)' => ['ok', Level::Info],
            'small ints are not guessed' => ['3', null],
            'unknown' => ['banana', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('tryParseCases')]
    public function testTryParse(string $raw, ?Level $expected): void
    {
        self::assertSame($expected, Level::tryParse($raw));
    }

    public function testSyslogSeverityNumbers(): void
    {
        self::assertSame(Level::Emergency, Level::fromSyslog(0));
        self::assertSame(Level::Error, Level::fromSyslog(3));
        self::assertSame(Level::Debug, Level::fromSyslog(7));
        self::assertNull(Level::fromSyslog(8));
    }

    public function testOrderingFollowsPsr3(): void
    {
        self::assertTrue(Level::Error->atLeast(Level::Warning));
        self::assertTrue(Level::Error->atLeast(Level::Error));
        self::assertFalse(Level::Warning->atLeast(Level::Error));
        self::assertTrue(Level::Emergency->atLeast(Level::Debug));
    }
}
