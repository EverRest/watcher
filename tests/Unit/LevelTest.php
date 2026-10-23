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

    public function testOrderingFollowsPsr3(): void
    {
        self::assertTrue(Level::Error->atLeast(Level::Warning));
        self::assertTrue(Level::Error->atLeast(Level::Error));
        self::assertFalse(Level::Warning->atLeast(Level::Error));
        self::assertTrue(Level::Emergency->atLeast(Level::Debug));
    }
}
