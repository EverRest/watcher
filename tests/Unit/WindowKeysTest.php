<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Schedule\Window;
use Watcher\State\Keys;
use Watcher\Watcher;
use Watcher\WindowKeys;

final class WindowKeysTest extends TestCase
{
    public function testKeysAreTheOnesWatcherBuiltByHandBefore(): void
    {
        $start = new DateTimeImmutable('2026-10-08T06:00:00+00:00');
        $end = new DateTimeImmutable('2026-10-09T06:00:00+00:00');
        $k = new WindowKeys('api@stg', new Window($start, $end), 2592000, 900);
        $w = Watcher::windowKey($end);

        self::assertSame(Keys::lock('api@stg', $w), $k->lock());
        self::assertSame(900, $k->lockTtl());
        self::assertSame(Keys::run('api@stg', $w), $k->run());
        self::assertSame($w, $k->end());
        self::assertSame(Keys::snapshot('api@stg', Watcher::windowKey($start)), $k->previousSnapshot());
        self::assertSame(Keys::reported('api@stg', 'abc'), $k->reported('abc'));
        self::assertSame(Keys::delivered('api@stg', $w), $k->delivered());
        self::assertSame(Keys::analysisFailures('api@stg', $w), $k->analysisFailures());
        self::assertSame(Keys::partial('api@stg', $w), $k->partial());
        self::assertSame(Keys::failNotices('api@stg', $w), $k->failNotices());
    }
}
