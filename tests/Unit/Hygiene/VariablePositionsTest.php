<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\VariablePositions;
use Watcher\Level;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\Reducer;
use Watcher\Source\LogEvent;

final class VariablePositionsTest extends TestCase
{
    private static function positions(): VariablePositions
    {
        return new VariablePositions(new Redactor(new Normalizer()));
    }

    public function testTemplatesAtOrAboveTheThresholdAreListedWithCountsOnly(): void
    {
        $rows = self::positions()->find([
            ['template' => 'Payment declined for <*> <*>', 'distinct' => 25],
            ['template' => 'Cache miss for <*>', 'distinct' => 5],
        ], 20);

        self::assertSame([['tpl' => 'Payment declined for <*> <*>', 'positions' => 2, 'distinct_messages' => 25]], $rows);
    }

    public function testTheThresholdIsInclusive(): void
    {
        $in = [['template' => 'Hello <*>', 'distinct' => 3]];

        self::assertCount(1, self::positions()->find($in, 3));
        self::assertSame([], self::positions()->find($in, 4));
    }

    public function testTheSameTemplateFromTwoClustersIsOneRow(): void
    {
        $rows = self::positions()->find([['template' => 'Hello <*>', 'distinct' => 12], ['template' => 'Hello <*>', 'distinct' => 9]], 20);

        self::assertSame([['tpl' => 'Hello <*>', 'positions' => 1, 'distinct_messages' => 21]], $rows);
    }

    public function testTheTemplateIsScannedAgainBeforeItIsReported(): void
    {
        $rows = self::positions()->find([['template' => 'mailed anna.kowalski@example.org about <*>', 'distinct' => 4]], 2);

        self::assertSame('mailed <EMAIL> about <*>', $rows[0]['tpl']);
    }

    public function testBiggestFirstThenByTemplate(): void
    {
        $rows = self::positions()->find([
            ['template' => 'B <*>', 'distinct' => 3],
            ['template' => 'C <*>', 'distinct' => 5],
            ['template' => 'A <*>', 'distinct' => 5],
        ], 3);

        self::assertSame(['A <*>', 'C <*>', 'B <*>'], array_column($rows, 'tpl'));
    }

    public function testItFindsTheVaryingWordOfARealReductionWithoutKeepingIt(): void
    {
        $events = [];
        for ($i = 0; $i < 30; ++$i) {
            $name = chr(97 + $i % 26) . chr(97 + intdiv($i, 26)) . 'orvath';
            for ($k = 0; $k < 3; ++$k) {
                $events[] = new LogEvent(new DateTimeImmutable('2026-10-07T10:00:00+00:00'), Level::Warning, "Payment declined for $name", null, [], [], []);
            }
        }
        $reducer = new Reducer(new Normalizer(), 3);
        $reducer->reduce($events);

        $rows = self::positions()->find($reducer->variability(), 20);

        self::assertSame([['tpl' => 'Payment declined for <*>', 'positions' => 1, 'distinct_messages' => 30]], $rows);
        self::assertStringNotContainsString('orvath', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
