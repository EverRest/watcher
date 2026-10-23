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

    public function testDigitBasedExtraPatternsMaskTheTemplateAndMergeTheGroup(): void
    {
        $r = new Reducer(new Normalizer(extraPatterns: ['cust' => '/CUST-\d{6}/']), 3);
        $groups = $r->reduce([
            $this->ev('Invoice CUST-111111 rejected'),
            $this->ev('Invoice CUST-222222 rejected'),
            $this->ev('Invoice CUST-333333 rejected'),
        ]);

        self::assertCount(1, $groups);
        self::assertSame('Invoice <CUST> rejected', $groups[0]->template);
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

    /**
     * Fingerprint of 'Lockwl timeout for order' is all decimal digits. As an array key PHP turns it into an int,
     * which strict_types rejects for ProblemGroup's string $fingerprint.
     */
    public function testAllDigitFingerprintStaysAString(): void
    {
        $groups = (new Reducer(new Normalizer(), 3))->reduce([$this->ev('Lockwl timeout for order')]);

        self::assertCount(1, $groups);
        self::assertSame('694072486490', $groups[0]->fingerprint);
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

    /** Distinct word-pair messages: the fingerprint hashes the first two words, so each is its own group. */
    private function distinct(int $n, Level $l = Level::Error): Generator
    {
        for ($i = 0; $i < $n; ++$i) {
            yield $this->ev('alpha' . self::word($i) . ' beta' . self::word($i) . ' failed', '2026-01-01', $l);
        }
    }

    private static function word(int $i): string
    {
        return strtr((string) $i, '0123456789', 'abcdefghij');
    }

    public function testUnderTheCapNothingOverflows(): void
    {
        $r = new Reducer(new Normalizer(), 3, 10);
        $groups = $r->reduce($this->distinct(10));

        self::assertCount(10, $groups);
        self::assertSame(0, $r->overflowedEvents());
    }

    public function testBeyondTheCapNewFingerprintsFoldIntoOneOverflowGroupPerLevel(): void
    {
        $r = new Reducer(new Normalizer(), 3, 5);
        $events = [...$this->distinct(5), ...$this->distinct(20), ...iterator_to_array($this->distinct(7, Level::Warning), false)];
        // the 5 first Error messages are re-seen 3 more times each (20 of the second batch overlap on 5)
        $groups = $r->reduce($events);

        self::assertSame(array_sum(array_map(static fn ($g) => $g->count, $groups)), count($events));
        $overflow = array_values(array_filter($groups, static fn ($g) => $g->template === '<other clusters>'));
        self::assertCount(2, $overflow);
        self::assertNotSame($overflow[0]->fingerprint, $overflow[1]->fingerprint);
        self::assertSame(15 + 7, $r->overflowedEvents()); // 20 distinct errors: 5 already own, 15 new; 7 warnings
        self::assertLessThanOrEqual(5 + 2, count($groups));
        foreach ($overflow as $g) {
            self::assertNull($g->errorType);
            self::assertSame([], $g->frames);
            self::assertSame($g->level === Level::Error ? 15 : 7, $g->count);
        }
        $errorOverflow = array_values(array_filter($overflow, static fn ($g) => $g->level === Level::Error))[0];
        $again = (new Reducer(new Normalizer(), 3, 5))->reduce($events);
        self::assertContains($errorOverflow->fingerprint, array_map(static fn ($g) => $g->fingerprint, $again));
    }

    public function testExistingGroupsKeepCountingOverTheCap(): void
    {
        $r = new Reducer(new Normalizer(), 3, 2);
        $groups = $r->reduce([...$this->distinct(2), ...$this->distinct(2), ...$this->distinct(2)]);

        self::assertCount(2, $groups);
        self::assertSame([3, 3], array_map(static fn ($g) => $g->count, $groups));
        self::assertSame(0, $r->overflowedEvents());
    }

    public function testOverflowedEventsDoNotFeedTheMiner(): void
    {
        // Same cluster key (level|type|token count|frame): if they reached the miner, one template would form.
        $r = new Reducer(new Normalizer(), 3, 1);
        $events = [$this->ev('first thing one failed'), $this->ev('second thing two failed'), $this->ev('third thing three failed'), $this->ev('fourth thing four failed')];
        $groups = $r->reduce($events);

        self::assertCount(2, $groups);
        $own = array_values(array_filter($groups, static fn ($g) => $g->template !== '<other clusters>'));
        self::assertCount(1, $own);
        self::assertNull($own[0]->template); // a cluster of 1 event: withheld, so the miner saw only the first event
    }

    public function testExemptEventsKeepTheirOwnGroupUpToTwiceTheCap(): void
    {
        $r = new Reducer(new Normalizer(), 3, 2, static fn (LogEvent $e): bool => str_contains($e->message, 'watched'));
        $events = [...$this->distinct(2)];
        for ($i = 0; $i < 6; ++$i) {
            $events[] = $this->ev('watched' . self::word($i) . ' item' . self::word($i) . ' failed');
        }
        $groups = $r->reduce($events);

        $own = array_filter($groups, static fn ($g) => $g->template !== '<other clusters>');
        self::assertCount(4, $own); // 2 plain + 2 exempt up to the ceiling 2 x cap
        self::assertSame(4, $r->overflowedEvents()); // the remaining exempt events fold
        self::assertSame(8, array_sum(array_map(static fn ($g) => $g->count, $groups)));
    }

    public function testOnFoldedIsCalledWithTheFoldedEventsOnly(): void
    {
        $folded = [];
        $r = new Reducer(new Normalizer(), 3, 1, null, static function (LogEvent $e) use (&$folded): void {
            $folded[] = $e->message;
        });
        $r->reduce([$this->ev('keep this one'), $this->ev('drop that other'), $this->ev('keep this one')]);

        self::assertSame(['drop that other'], $folded);
    }
}
