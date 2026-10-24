<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\ProblemGroup;
use Watcher\Reduce\Reducer;
use Watcher\Source\EventAssembler;
use Watcher\Source\LineParser;
use Watcher\Source\LogEvent;

/** Spec 004 US3: templates stay readable (SC-003) and cost at most 10% more to send than the length-only ones (SC-007). */
final class TemplateReadabilityTest extends TestCase
{
    private const MIN_EVENTS = 3;

    /** @return list<LogEvent> */
    private static function events(): array
    {
        $parser = new LineParser();
        $files = [...(glob(__DIR__ . '/../Fixtures/logs/*.log') ?: []), __DIR__ . '/../Fixtures/templates/same_length_mixed.log'];
        $events = [];
        foreach ($files as $file) {
            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file)), static fn (string $l): bool => $l !== ''));
            foreach ((new EventAssembler($parser))->assemble($lines) as $record) {
                $events[] = $parser->parse($record);
            }
        }

        return $events;
    }

    /** @return list<ProblemGroup> */
    private static function reduced(bool $similarity): array
    {
        return (new Reducer(new Normalizer(), self::MIN_EVENTS, groupBySimilarity: $similarity))->reduce(self::events());
    }

    public function testMostTextedGroupsAreMostlyLiteralWords(): void
    {
        $texted = array_values(array_filter(self::reduced(true), static fn (ProblemGroup $g): bool => $g->count >= self::MIN_EVENTS));
        self::assertGreaterThanOrEqual(10, count($texted));

        $readable = 0;
        foreach ($texted as $g) {
            self::assertNotNull($g->template);
            $words = explode(' ', $g->template);
            $literal = count(array_filter($words, static fn (string $w): bool => $w !== '<*>'));
            $readable += $literal * 2 >= count($words) ? 1 : 0;
        }
        self::assertGreaterThanOrEqual(0.8, $readable / count($texted), 'share of groups with at least half literal words');
    }

    public function testSameLengthProblemsGetTheirOwnTemplate(): void
    {
        $templates = array_map(static fn (ProblemGroup $g): ?string => $g->template, self::reduced(true));

        self::assertContains('Report export failed for customer <*> <*>', $templates);
        self::assertContains('Payment gateway timed out after <N> retries', $templates);
        self::assertNotContains('<*> <*> <*> <*> <*> <*> <*>', $templates);
    }

    public function testTemplatePayloadGrowsByAtMostTenPercent(): void
    {
        $size = static fn (array $groups): int => array_sum(array_map(static fn (ProblemGroup $g): int => strlen((string) $g->template), $groups));

        $old = $size(self::reduced(false));
        $new = $size(self::reduced(true));

        self::assertGreaterThan(0, $old);
        self::assertLessThanOrEqual($old * 1.10, $new, "template bytes old=$old new=$new");
    }
}
