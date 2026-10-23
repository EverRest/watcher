<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Select;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\ProblemGroup;
use Watcher\Select\CandidateReason as R;
use Watcher\Select\Selector;

final class SelectorTest extends TestCase
{
    private function g(string $fp, Level $l, int $n, ?int $prev = null): ProblemGroup
    {
        $t = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return (new ProblemGroup($fp, 'tpl', $l, null, [], $n, $t, $t))->withPrevious($prev, 3.0);
    }

    /** @return array<string, list<R>> fingerprint => reasons */
    private function reasons(\Watcher\Select\SelectionResult $r): array
    {
        $o = [];
        foreach ($r->candidates as $c) {
            $o[$c->group->fingerprint] = $c->reasons;
        }

        return $o;
    }

    public function testReasonsTable(): void
    {
        $groups = [
            $this->g('new', Level::Warning, 1),                  // new
            $this->g('grown', Level::Warning, 30, 10),           // grown
            $this->g('lvl', Level::Error, 5, 5),                 // level (unchanged)
            $this->g('anom', Level::Info, 5, 5),                 // level anomaly
            $this->g('vol', Level::Warning, 100, 100),           // top volume only
            $this->g('rest1', Level::Warning, 2, 2),
            $this->g('rest2', Level::Warning, 3, 3),
        ];
        $r = (new Selector())->select($groups, Level::Error, 1, [Level::Info]);

        $got = array_intersect_key($this->reasons($r), array_flip(['new', 'grown', 'lvl', 'anom', 'vol']));
        ksort($got);
        self::assertSame(
            [
                'anom' => [R::LevelAnomaly],
                'grown' => [R::Grown],
                'lvl' => [R::Level],
                'new' => [R::New],
                'vol' => [R::TopVolume],
            ],
            $got,
        );
        self::assertSame(2, $r->otherGroups);
        self::assertSame(5, $r->otherEvents);
        self::assertCount(5, $r->candidates);
    }

    public function testMultipleReasonsAccumulate(): void
    {
        $r = (new Selector())->select([$this->g('a', Level::Error, 9)], Level::Error, 5);
        self::assertSame([R::New, R::Level, R::TopVolume], $r->candidates[0]->reasons);
    }

    public function testDeterministicOrderPriorityThenFingerprint(): void
    {
        $groups = [
            $this->g('b', Level::Error, 1, 1),
            $this->g('a', Level::Error, 1, 1),
            $this->g('z', Level::Critical, 1, 1),
        ];
        $r = (new Selector())->select($groups, Level::Error, 0);
        self::assertSame(['z', 'a', 'b'], array_map(static fn ($c) => $c->group->fingerprint, $r->candidates));
        self::assertGreaterThan($r->candidates[1]->priority, $r->candidates[0]->priority);
        self::assertSame($r->candidates[1]->priority, $r->candidates[2]->priority);
    }

    public function testTopNTieBreaksByFingerprint(): void
    {
        $groups = [$this->g('b', Level::Info, 5, 5), $this->g('a', Level::Info, 5, 5), $this->g('c', Level::Info, 1, 1)];
        $r = (new Selector())->select($groups, Level::Error, 1);
        self::assertSame(['a'], array_keys($this->reasons($r)));
        self::assertSame(2, $r->otherGroups);
        self::assertSame(6, $r->otherEvents);
    }

    public function testNothingSelectedIsEmpty(): void
    {
        $r = (new Selector())->select([$this->g('x', Level::Info, 1, 1)], Level::Error, 0);
        self::assertSame([], $r->candidates);
        self::assertSame(1, $r->otherGroups);
        self::assertSame(1, $r->otherEvents);

        $e = (new Selector())->select([], Level::Error, 5);
        self::assertSame([], $e->candidates);
        self::assertSame(0, $e->otherGroups);
    }

    public function testWatchMatchedLowLevelGroupIsAWatchRuleCandidate(): void
    {
        $t = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $watched = new ProblemGroup('w', 'tpl', Level::Info, null, [], 1, $t, $t, 1, \Watcher\Reduce\GroupStatus::Unchanged, ['rule']);
        $r = (new Selector())->select([$watched], Level::Error, 0);
        self::assertSame([R::WatchRule], $this->reasons($r)['w']);
    }
}
