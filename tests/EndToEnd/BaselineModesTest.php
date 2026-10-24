<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Tests\Fakes\FakeLogSource;

/** Spec 004 US2: a weekly pattern is not growth, a real jump still is, and the default is unchanged. */
final class BaselineModesTest extends TestCase
{
    /** Mon 30, Tue-Fri 10, Sat-Sun 3 */
    private const WEEK = ['Mon' => 30, 'Tue' => 10, 'Wed' => 10, 'Thu' => 10, 'Fri' => 10, 'Sat' => 3, 'Sun' => 3];

    private static function source(int $n): FakeLogSource
    {
        $events = Harness::events(Level::Error, 'Alpha service exploded badly', $n, 'AlphaException');

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    /**
     * Runs one window per day; returns the candidate count (new or grown, not yet reported) of each day after the first `$skip`.
     *
     * @param array<string, mixed> $config
     * @param callable(int, string): int $level day index, weekday => events
     * @return list<int>
     */
    private static function daily(array $config, int $days, int $skip, callable $level): array
    {
        // retention well past the run, so the `reported` markers do not expire and make the group a candidate again
        $h = new Harness(array_replace_recursive(['state' => ['retention' => 60 * 86400]], $config));
        $candidates = [];
        for ($d = 0; $d < $days; ++$d) {
            $result = $h->watcher(self::source($level($d, $h->clock->now()->format('D'))))->run();
            if ($d >= $skip) {
                $candidates[] = $result->summaryData?->stats?->candidates ?? 0;
            }
            $h->nextWindow();
        }

        return $candidates;
    }

    public function testWeeklyPatternHasNoFalseGrowthInWeekdayMode(): void
    {
        $level = static fn (int $d, string $day): int => self::WEEK[$day];

        // the problem is reported on day 1; after that only growth makes it a candidate again
        $weekday = self::daily(['baseline' => ['mode' => 'weekday']], 36, 29, $level);
        $previous = self::daily([], 36, 29, $level);

        self::assertSame(0, array_sum($weekday));
        self::assertGreaterThanOrEqual(1, array_sum($previous), 'Monday against Sunday is a 10x jump in previous mode');
    }

    public function testRealJumpIsGrownInBothModes(): void
    {
        $level = static fn (int $d, string $day): int => $d === 30 ? 120 : 10;

        foreach ([['baseline' => ['mode' => 'weekday']], ['baseline' => ['mode' => 'rolling']], []] as $config) {
            self::assertSame([1], self::daily($config, 31, 30, $level), json_encode($config) ?: '');
        }
    }

    public function testDefaultModeIsUnchanged(): void
    {
        $level = static fn (int $d, string $day): int => self::WEEK[$day];

        self::assertSame(
            self::daily([], 12, 0, $level),
            self::daily(['baseline' => ['mode' => 'previous']], 12, 0, $level),
        );
    }

    public function testDigestNamesTheComparisonUsed(): void
    {
        $h = new Harness(['baseline' => ['mode' => 'weekday']]);
        for ($d = 0; $d < 31; ++$d) {
            $h->watcher(self::source($d === 30 ? 120 : 10))->run();
            $h->nextWindow();
        }
        $h->clock->advance('-24 hours');

        $text = implode("\n", $h->sentTexts());
        self::assertStringContainsString('Compared with the median of the last 4 ', $text);
        self::assertStringContainsString('(↑ from 10)', $text);
    }
}
