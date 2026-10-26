<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Report;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Report\Week;

final class WeekTest extends TestCase
{
    public function testFromIsoAndContains(): void
    {
        $w = Week::fromIso('2026-W43');
        self::assertSame('2026-W43', $w->iso);
        self::assertTrue($w->containsWindowEnd(new DateTimeImmutable('2026-10-22T06:00:00Z')));
        self::assertFalse($w->containsWindowEnd(new DateTimeImmutable('2026-10-26T06:00:00Z')));
        self::assertSame(['2026-10-19', '2026-10-25'], $w->labelDates());
    }

    public function testLastComplete(): void
    {
        $w = Week::lastComplete(new DateTimeImmutable('2026-10-26T08:00:00Z'));
        self::assertSame('2026-W43', $w->iso);
    }
}
