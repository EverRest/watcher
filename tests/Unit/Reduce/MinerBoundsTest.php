<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\TemplateMiner;
use Watcher\Source\LogEvent;

final class MinerBoundsTest extends TestCase
{
    private static function ev(): LogEvent
    {
        return new LogEvent(new DateTimeImmutable('2026-01-01'), Level::Error, 'ignored', null, ['a.php:1'], [], []);
    }

    public function testCapReportsTruncationAndWithholds(): void
    {
        $m = new TemplateMiner(1, maxDistinct: 3);
        for ($i = 1; $i <= 5; ++$i) {
            for ($k = 0; $k < 3; ++$k) {
                $m->add(self::ev(), ["w$i", "x$i"], "fp$i");
            }
        }

        $s = $m->stats();
        self::assertSame(3, $s->distinctMessages);
        self::assertSame(6, $s->droppedMessages); // events of the two messages that did not fit
        self::assertFalse($s->budgetExhausted);
        self::assertTrue($s->truncated());
        self::assertSame('w1 x1', $m->template('fp1'));
        self::assertSame('w3 x3', $m->template('fp3'));
        self::assertNull($m->template('fp4'));
        self::assertNull($m->template('fp5'));
    }

    public function testBudgetExhaustionWithholdsRemaining(): void
    {
        $m = new TemplateMiner(1, comparisonBudget: 1);
        foreach (['aaa x', 'bbb y', 'ccc z', 'ddd w'] as $i => $text) {
            $m->add(self::ev(), explode(' ', $text), "fp$i");
        }

        $s = $m->stats();
        self::assertTrue($s->budgetExhausted);
        self::assertSame(0, $s->droppedMessages);
        self::assertSame('aaa x', $m->template('fp0'));
        self::assertSame('bbb y', $m->template('fp1'));
        self::assertNull($m->template('fp2'));
        self::assertNull($m->template('fp3'));
    }

    public function testUnderTheBoundsNothingIsTruncated(): void
    {
        $m = new TemplateMiner(1);
        $m->add(self::ev(), ['a', 'b'], 'fp');

        self::assertFalse($m->stats()->truncated());
        self::assertSame(1, $m->stats()->distinctMessages);
    }

    /** SC-005 scaled by 10: 10,000 unrelated distinct messages finish, deterministically and in bounded memory. */
    public function testManyDistinctMessagesAreBounded(): void
    {
        $before = memory_get_peak_usage();
        $t = microtime(true);
        $m = self::fill(new TemplateMiner(1, maxDistinct: 10_000), 10_000);
        $s = $m->stats();

        self::assertSame(10_000, $s->distinctMessages);
        self::assertLessThan(30.0, microtime(true) - $t);
        self::assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $before);
        // budget is 400 x cap = 4,000,000 comparisons; 10,000 unrelated messages need ~50,000,000
        self::assertTrue($s->budgetExhausted);
        $again = self::fill(new TemplateMiner(1, maxDistinct: 10_000), 10_000);
        self::assertSame($m->template('fp5'), $again->template('fp5'));
        self::assertSame($m->template('fp9999'), $again->template('fp9999'));
    }

    /** @group perf */
    public function testFullSizeHundredThousandDistinctMessages(): void
    {
        $before = memory_get_peak_usage();
        $t = microtime(true);
        $s = self::fill(new TemplateMiner(1, maxDistinct: 100_000), 100_000)->stats();

        self::assertSame(100_000, $s->distinctMessages);
        self::assertLessThan(60.0, microtime(true) - $t);
        self::assertLessThan(256 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    private static function fill(TemplateMiner $m, int $n): TemplateMiner
    {
        for ($i = 0; $i < $n; ++$i) {
            $m->add(self::ev(), ["a$i", "b$i", "c$i", "d$i", "e$i", "f$i"], "fp$i");
        }

        return $m;
    }
}
