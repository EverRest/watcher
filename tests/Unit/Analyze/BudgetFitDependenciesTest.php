<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Budget;
use Watcher\Analyze\CompactPayload;
use Watcher\Context\DependencyContext;
use Watcher\Level;
use Watcher\Privacy\RedactedGroup;
use Watcher\Privacy\GroupStatusLabel;

/** fitDependencies drops dependency top errors, lowest count first (the later one on a tie), and never anything else. */
final class BudgetFitDependenciesTest extends TestCase
{
    private static function group(string $fp, int $count): RedactedGroup
    {
        return new RedactedGroup($fp, 'template of ' . $fp . ' with some words', Level::Error, null, [], $count, null, GroupStatusLabel::New, [], new DateTimeImmutable('@0'), new DateTimeImmutable('@0'));
    }

    /**
     * @param list<list<int>> $counts per dependency, its top errors' counts
     * @return list<DependencyContext>
     */
    private static function deps(array $counts): array
    {
        $out = [];
        foreach ($counts as $i => $cs) {
            $top = [];
            foreach ($cs as $j => $c) {
                $top[] = self::group("d{$i}g{$j}", $c);
            }
            $out[] = new DependencyContext("dep{$i}", true, ['error' => 5], null, $top, [0], false);
        }

        return $out;
    }

    /**
     * The implementation as it was on origin/develop before the phpstan 2 change.
     *
     * @param list<RedactedGroup>     $groups
     * @param list<DependencyContext> $deps
     *
     * @return array<int, DependencyContext>
     */
    private static function reference(array $groups, array $deps, int $max, int $fixed): array
    {
        while ($deps !== [] && Budget::estimateTokens(CompactPayload::encode($groups, array_values($deps))) + $fixed > $max) {
            $at = null;
            foreach ($deps as $i => $d) {
                foreach ($d->topErrors as $j => $g) {
                    if ($at === null || $g->count <= $deps[$at[0]]->topErrors[$at[1]]->count) {
                        $at = [$i, $j];
                    }
                }
            }
            if ($at === null) {
                break;
            }
            $top = $deps[$at[0]]->topErrors;
            unset($top[$at[1]]);
            $deps[$at[0]] = $deps[$at[0]]->withTopErrors(array_values($top));
        }

        return $deps;
    }

    /**
     * @param array<int, DependencyContext> $deps
     *
     * @return list<string>
     */
    private static function shape(array $deps): array
    {
        return array_values(array_map(static fn (DependencyContext $d): string => $d->name . ':' . implode(',', array_map(static fn (RedactedGroup $g): string => $g->fingerprint . '=' . $g->count, $d->topErrors)), $deps));
    }

    public function testItMatchesTheOriginalImplementationAcrossBudgets(): void
    {
        $inputs = [
            [[5, 3, 9], [4, 4], [1]],
            [[2, 2, 2], [2, 2]],
            [[7], [], [3, 8, 1, 6, 2]],
            [[], []],
            [[10, 20, 30, 40, 50]],
        ];
        $groups = [self::group('g', 4)];
        foreach ($inputs as $counts) {
            $full = Budget::estimateTokens(CompactPayload::encode($groups, self::deps($counts)));
            foreach ([0, 1, 5, intdiv($full, 3), intdiv($full, 2), $full - 1, $full, $full + 100] as $max) {
                foreach ([0, 7] as $fixed) {
                    self::assertSame(
                        self::shape(self::reference($groups, self::deps($counts), $max, $fixed)),
                        self::shape(Budget::fitDependencies($groups, self::deps($counts), $max, $fixed)),
                        'counts ' . json_encode($counts) . " max {$max} fixed {$fixed}",
                    );
                }
            }
        }
    }

    public function testLowestCountGoesFirstAndALaterTieLosesBeforeAnEarlierOne(): void
    {
        $groups = [self::group('g', 4)];
        $deps = self::deps([[5, 2], [2, 9]]);
        $full = Budget::estimateTokens(CompactPayload::encode($groups, $deps));
        $one = Budget::fitDependencies($groups, $deps, $full - 1);

        self::assertSame(['dep0:d0g0=5,d0g1=2', 'dep1:d1g1=9'], self::shape($one), 'the tie on 2 drops the later dependency\'s error');
    }

    public function testItStopsWhenNothingIsLeftToDropAndKeepsTheDependencies(): void
    {
        $out = Budget::fitDependencies([self::group('g', 1)], self::deps([[3], [4]]), 0);

        self::assertSame(['dep0:', 'dep1:'], self::shape($out));
    }
}
