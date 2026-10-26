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

    public function testTheOnlyVersionBadgeIsTheLiveLatestRelease(): void
    {
        $block = badges_block();
        self::assertStringContainsString('img.shields.io/github/v/release/EverRest/watcher', $block);
        self::assertStringNotContainsString('version.svg', $block);
        self::assertFalse(function_exists('badges_version'), 'a committed version badge goes stale: it was 0.1.2 after 0.3.1');
    }

    public function testCoverageFromCoberturaAndClover(): void
    {
        self::assertSame(87.5, badges_coverage('<coverage line-rate="0.875" branch-rate="0">'));
        $clover = '<coverage><project timestamp="1"><metrics files="2" statements="200" coveredstatements="150"/></project></coverage>';
        self::assertSame(75.0, badges_coverage($clover));
        self::assertNull(badges_coverage('<nothing/>'));
    }

    public function testThereIsNoCommittedCoverageBadge(): void
    {
        self::assertStringNotContainsString('coverage.svg', badges_block());
        self::assertFalse(function_exists('badges_coverage_color'), 'a committed coverage number goes stale: 96.5% two days and thousands of lines ago; CI enforces the floor');
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
