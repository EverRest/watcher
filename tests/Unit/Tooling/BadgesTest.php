<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/badges.php';

final class BadgesTest extends TestCase
{
    public function testPhpAndLicenseComeFromComposerJson(): void
    {
        $json = '{"license":"MIT","require":{"php":"^8.1"}}';
        self::assertSame('^8.1', badges_php($json));
        self::assertSame('MIT', badges_license($json));
        self::assertSame('unknown', badges_php('not json'));
    }

    public function testVersionPrefersTagThenChangelogThenUnreleased(): void
    {
        $log = "# Changelog\n\n## Unreleased\n\n## 1.2.3 - 2026-01-01\n";
        self::assertSame('2.0.0', badges_version('v2.0.0', $log));
        self::assertSame('1.2.3', badges_version(null, $log));
        self::assertSame('unreleased', badges_version('', "## Unreleased\n"));
        self::assertSame('unreleased', badges_version(null, "## Unreleased\n\n## 0.1.0 — unreleased draft\n"));
        self::assertSame('0.1.0', badges_version(null, "## Unreleased\n\n## 0.1.0 — 2026-11-01 — first release\n"));
    }

    public function testCoverageFromCoberturaAndClover(): void
    {
        self::assertSame(87.5, badges_coverage('<coverage line-rate="0.875" branch-rate="0">'));
        $clover = '<coverage><project timestamp="1"><metrics files="2" statements="200" coveredstatements="150"/></project></coverage>';
        self::assertSame(75.0, badges_coverage($clover));
        self::assertNull(badges_coverage('<nothing/>'));
    }

    public function testColorThresholds(): void
    {
        self::assertSame('#4c1', badges_coverage_color(80.0));
        self::assertSame('#e05d44', badges_coverage_color(10.0));
    }

    public function testInjectReplacesOnlyBetweenMarkers(): void
    {
        $readme = "# t\n" . BADGE_START . "\nold\n" . BADGE_END . "\nbody\n";
        $out = badges_inject($readme, 'NEW');
        self::assertSame("# t\n" . BADGE_START . "\nNEW\n" . BADGE_END . "\nbody\n", $out);
        self::assertSame($out, badges_inject($out, 'NEW'), 'idempotent');
        self::assertSame('no markers', badges_inject('no markers', 'NEW'));
    }

    public function testSvgEscapesText(): void
    {
        $svg = badges_svg('a<b', 'c&d', '#4c1');
        self::assertStringContainsString('a&lt;b', $svg);
        self::assertStringContainsString('c&amp;d', $svg);
    }
}
