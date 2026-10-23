<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use DateTimeImmutable;
use Generator;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\Reducer;
use Watcher\Source\LogEvent;

final class ReducerTest extends TestCase
{
    /** @param list<string> $frames */
    private function ev(string $msg, string $time = '2026-01-01', Level $l = Level::Error, array $frames = ['a.php:1']): LogEvent
    {
        return new LogEvent(new DateTimeImmutable($time), $l, $msg, null, $frames, [], []);
    }

    public function testFoldsIntoGroups(): void
    {
        $r = new Reducer(new Normalizer(), 3);
        $groups = $r->reduce([
            $this->ev('Lock timeout order=1', '2026-01-02'),
            $this->ev('Lock timeout order=2', '2026-01-01'),
            $this->ev('Lock timeout order=3', '2026-01-03', frames: ['a.php:1']),
            $this->ev('Other thing happened', '2026-01-05', Level::Warning, ['1', '2', '3', '4', '5', '6', '7']),
        ]);

        self::assertCount(2, $groups);
        [$a, $b] = $groups;
        self::assertSame(3, $a->count);
        self::assertSame('Lock timeout order=<N>', $a->template);
        self::assertSame('2026-01-01', $a->firstSeen->format('Y-m-d'));
        self::assertSame('2026-01-03', $a->lastSeen->format('Y-m-d'));
        self::assertSame(GroupStatus::New, $a->status);
        self::assertNull($a->previousCount);
        self::assertSame([], $a->watchMatches);
        self::assertSame(1, $b->count);
        self::assertNull($b->template);
        self::assertSame(Level::Warning, $b->level);
        self::assertSame(['1', '2', '3', '4', '5'], $b->frames);
    }

    public function testWithPrevious(): void
    {
        $g = (new Reducer(new Normalizer(), 1))->reduce([$this->ev('x'), $this->ev('x'), $this->ev('x')])[0];

        self::assertSame(GroupStatus::Grown, $g->withPrevious(1, 3.0)->status);
        self::assertSame(GroupStatus::Unchanged, $g->withPrevious(2, 3.0)->status);
        self::assertSame(1, $g->withPrevious(1, 3.0)->previousCount);
        self::assertSame(GroupStatus::New, $g->status);
    }

    public function testStreamsWithoutMaterialising(): void
    {
        $gen = (static function (): Generator {
            for ($i = 0; $i < 100_000; $i++) {
                yield new LogEvent(new DateTimeImmutable('2026-01-01'), Level::Error, 'Timeout for order ' . $i, null, ['a.php:1'], ['k' => str_repeat('x', 200)], []);
            }
        })();

        gc_collect_cycles();
        $before = memory_get_usage();
        $peakBefore = memory_get_peak_usage();
        $groups = (new Reducer(new Normalizer(), 3))->reduce($gen);

        self::assertCount(1, $groups);
        self::assertSame(100_000, $groups[0]->count);
        // 100k retained events would be well over 50 MB.
        self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - max($peakBefore, $before));
    }
}
