<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\HygieneSettings;
use Watcher\Hygiene\ScanResult;
use Watcher\Hygiene\ShapeReport;
use Watcher\Hygiene\SiteStats;

final class ShapeReportTest extends TestCase
{
    private static function site(string $id, string $level, int $lines, int $long = 0, int $trace = 0, int $mismatch = 0, string $kind = 'frame'): SiteStats
    {
        $s = new SiteStats('k-' . $id, $id, $kind, 'a1b2c3d4e5f6', $level, new DateTimeImmutable('2026-10-07T10:00:00+00:00'), new DateTimeImmutable('2026-10-07T11:00:00+00:00'));
        $s->lines = $lines;
        $s->long = $long;
        $s->trace = $trace;
        $s->mismatch = $mismatch;

        return $s;
    }

    /**
     * @param list<SiteStats> $sites
     * @param array<string, int> $formats
     */
    private static function report(array $sites, int $events, array $formats = [], int $maxLength = 10): ShapeReport
    {
        return ShapeReport::build(new ScanResult($events, 0, $sites, $formats ?: ['text' => $events], $maxLength), new HygieneSettings(enabled: true, maxLineLength: 2000, noisyShare: 0.20));
    }

    /** @return array<string, array<string, mixed>> habit => entry */
    private static function byHabit(ShapeReport $r): array
    {
        $out = [];
        foreach ($r->toArray()['habits'] as $h) {
            self::assertArrayNotHasKey($h['habit'], $out, 'each habit once');
            $out[$h['habit']] = $h;
        }

        return $out;
    }

    public function testACleanStreamIsEmpty(): void
    {
        $r = self::report([self::site('a.php:1', 'info', 10), self::site('b.php:1', 'info', 10), self::site('c.php:1', 'info', 10), self::site('d.php:1', 'info', 10), self::site('e.php:1', 'info', 10), self::site('f.php:1', 'info', 10)], 60);

        self::assertTrue($r->isEmpty());
        self::assertSame('shape: nothing to report', $r->text());
        self::assertSame([], $r->toArray()['habits']);
    }

    public function testEachHabitIsListedOnceWithItsMeasure(): void
    {
        $r = self::report(
            [
                self::site('noisy.php:1', 'info', 600),
                self::site('long.php:2', 'info', 100, long: 100),
                self::site('trace.php:3', 'error', 50, trace: 50),
                self::site('access.php:4', 'error', 200, mismatch: 150),
            ],
            1000,
            ['text' => 700, 'access' => 200, 'json' => 100],
            9120,
        );

        $h = self::byHabit($r);

        self::assertSame(['noisy_site', 'long_lines', 'trace_in_message', 'mixed_format', 'level_mismatch'], array_keys($h));
        self::assertSame(['habit' => 'noisy_site', 'site' => 'noisy.php:1', 'level' => 'info', 'count' => 600, 'share' => 0.6, 'measure' => ['limit' => 0.2],
            'proposal' => $h['noisy_site']['proposal']], $h['noisy_site']);
        self::assertSame(
            ['site' => 'long.php:2', 'count' => 100, 'share' => 0.1, 'measure' => ['limit' => 2000, 'max_length' => 9120]],
            array_intersect_key($h['long_lines'], array_flip(['site', 'count', 'share', 'measure']))
        );
        self::assertSame(['site' => 'trace.php:3', 'count' => 50, 'share' => 0.05], array_intersect_key($h['trace_in_message'], array_flip(['site', 'count', 'share'])));
        self::assertSame(['count' => 300, 'measure' => ['dominant' => 'text', 'formats' => ['access' => 200, 'json' => 100]]], array_intersect_key($h['mixed_format'], array_flip(['count', 'measure'])));
        self::assertNull($h['mixed_format']['site']);
        self::assertSame(['site' => 'access.php:4', 'count' => 150, 'share' => 0.15], array_intersect_key($h['level_mismatch'], array_flip(['site', 'count', 'share'])));
    }

    public function testASiteBelowTheShareIsNotNoisy(): void
    {
        $r = self::report([self::site('a.php:1', 'info', 199), self::site('b.php:1', 'info', 801)], 1000);

        self::assertSame(['b.php:1'], array_column($r->toArray()['habits'], 'site'));
    }

    public function testSeveralNoisySitesAreListedOneEach(): void
    {
        $r = self::report([self::site('a.php:1', 'info', 400), self::site('b.php:1', 'debug', 400), self::site('c.php:1', 'info', 200)], 1000);

        self::assertSame(['a.php:1', 'b.php:1'], array_column($r->toArray()['habits'], 'site'));
    }

    public function testAFormatBelowOnePercentIsNotMixing(): void
    {
        $r = self::report([self::site('a.php:1', 'info', 100)], 1000, ['text' => 995, 'json' => 5]);

        self::assertTrue($r->isEmpty());
    }

    public function testEveryEntryProposesARuleAsTextOnly(): void
    {
        $r = self::report([self::site('noisy.php:1', 'info', 600), self::site('tpl', 'info', 10, kind: 'template')], 1000);

        $proposal = $r->toArray()['habits'][0]['proposal'];
        self::assertStringContainsString('noisy.php:1', $proposal);
        self::assertStringContainsString("'fingerprint' => 'a1b2c3d4e5f6'", $proposal);
        self::assertStringContainsString('lower the level', $proposal);
    }

    public function testAnErrorLevelNoisySiteIsNotToldToLowerItsLevel(): void
    {
        $r = self::report([self::site('noisy.php:1', 'error', 600)], 1000);

        self::assertStringNotContainsString('lower the level', $r->toArray()['habits'][0]['proposal']);
    }

    public function testTheTextAndBodyCarryNoMessageText(): void
    {
        $r = self::report([self::site('noisy.php:1', 'info', 600)], 1000);

        self::assertSame(['habits', 'formats'], array_keys($r->toArray()));
        self::assertStringContainsString('noisy_site', $r->text());
        self::assertStringContainsString('noisy.php:1', $r->text());
        self::assertStringContainsString('60%', $r->text());
    }
}
