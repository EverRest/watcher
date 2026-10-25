<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Select;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\ProblemGroup;
use Watcher\Select\Candidate;
use Watcher\Select\CandidateReason;
use Watcher\Select\Selector;
use Watcher\Select\Tier;

/** Review M4: log groups at Error or above are never displaced by anomaly or Sentry groups; those only fill the slots that remain. */
final class TierTest extends TestCase
{
    private function group(string $fp, Level $level, int $count, GroupStatus $status = GroupStatus::New): ProblemGroup
    {
        $t = new DateTimeImmutable('2026-10-08T01:00:00Z');

        return new ProblemGroup($fp, 'tpl ' . $fp, $level, null, [], $count, $t, $t, null, $status);
    }

    private function cand(ProblemGroup $g, CandidateReason $r): Candidate
    {
        return new Candidate($g, [$r], $r->weight() * 10 + $g->level->value);
    }

    /**
     * @param list<Candidate> $cs
     *
     * @return list<string>
     */
    private static function order(array $cs): array
    {
        return array_map(static fn (Candidate $c): string => $c->group->fingerprint, $cs);
    }

    public function testExtrasRankBelowEveryLogErrorEvenWithAHigherNaturalPriority(): void
    {
        $log = [$this->cand($this->group('log-err', Level::Error, 5), CandidateReason::TopVolume), $this->cand($this->group('log-warn', Level::Warning, 9), CandidateReason::TopVolume)];
        $extra = [$this->cand($this->group('sentry:1', Level::Critical, 500), CandidateReason::MetricAnomaly), $this->cand($this->group('metric:x', Level::Warning, 300), CandidateReason::MetricAnomaly)];
        self::assertGreaterThan($log[0]->priority, $extra[0]->priority, 'precondition: the extra outranks the log error by itself');

        $merged = Tier::merge($log, $extra);

        self::assertLessThan(array_search('sentry:1', self::order($merged), true), array_search('log-err', self::order($merged), true));
        $rest = array_slice(array_values(array_filter(self::order($merged), static fn (string $f): bool => $f !== 'log-warn')), 1);
        sort($rest);
        self::assertSame(['metric:x', 'sentry:1'], $rest);
        foreach ($merged as $c) {
            if (str_contains($c->group->fingerprint, ':')) {
                self::assertLessThan($log[0]->priority, $c->priority);
            }
        }
    }

    public function testNoLogErrorMeansNoDemotion(): void
    {
        $extra = [$this->cand($this->group('sentry:1', Level::Error, 5), CandidateReason::Level)];
        $merged = Tier::merge([$this->cand($this->group('log-warn', Level::Warning, 9), CandidateReason::TopVolume)], $extra);

        self::assertSame($extra[0]->priority, $merged[0]->priority);
    }

    public function testTopNPressureFromSentryNeverPushesALogErrorOutOfTheVolumeSlots(): void
    {
        $logs = [$this->group('log-err', Level::Error, 3, GroupStatus::Unchanged), $this->group('log-info', Level::Info, 1, GroupStatus::Unchanged)];
        $sentry = [];
        for ($i = 1; $i <= 20; ++$i) {
            $sentry[] = $this->group("sentry:$i", Level::Error, 1000 + $i, GroupStatus::Unchanged);
        }
        // min_level Critical: the log error qualifies only by volume (top_n = 2)
        $alone = (new Selector())->select($logs, Level::Critical, 2);
        $mixedOld = (new Selector())->select([...$logs, ...$sentry], Level::Critical, 2);
        self::assertContains('log-err', self::order($alone->candidates));
        self::assertNotContains('log-err', self::order($mixedOld->candidates), 'the pressure is real when selection is shared');

        $selection = Tier::select([...$logs, ...$sentry], Level::Critical, 2, []);

        self::assertContains('log-err', self::order($selection->candidates));
        self::assertSame('log-err', self::order($selection->candidates)[0]);
    }

    public function testSentryGetsOnlyTheVolumeSlotsTheLogGroupsLeaveFree(): void
    {
        $sentry = [$this->group('sentry:1', Level::Warning, 900, GroupStatus::Unchanged), $this->group('sentry:2', Level::Warning, 800, GroupStatus::Unchanged)];
        $full = Tier::select([$this->group('a', Level::Info, 1, GroupStatus::Unchanged), $this->group('b', Level::Info, 1, GroupStatus::Unchanged), ...$sentry], Level::Critical, 2, []);
        $oneFree = Tier::select([$this->group('a', Level::Info, 1, GroupStatus::Unchanged), ...$sentry], Level::Critical, 2, []);

        self::assertSame(['a', 'b'], self::order($full->candidates));
        self::assertSame(2, $full->otherGroups);
        self::assertSame(['sentry:1', 'a'], self::order($oneFree->candidates));
    }
}
