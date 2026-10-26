<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\Reducer;
use Watcher\Source\LogEvent;

/** Spec 006 FR-002: how many distinct messages a template folds, counts only. */
final class VariabilityTest extends TestCase
{
    /** @return list<LogEvent> $perMessage events for each of $words, in message form "$prefix $word" */
    private static function events(string $prefix, int $words, int $perMessage, Level $level = Level::Warning): array
    {
        $out = [];
        for ($i = 0; $i < $words; ++$i) {
            $word = chr(97 + $i % 26) . chr(97 + intdiv($i, 26)) . 'orvath';
            for ($k = 0; $k < $perMessage; ++$k) {
                $out[] = new LogEvent(new DateTimeImmutable('2026-10-07T10:00:00+00:00'), $level, "$prefix $word", null, [], [], []);
            }
        }

        return $out;
    }

    /** @param list<LogEvent> $events */
    private static function reduce(array $events, int $minEvents = 3): Reducer
    {
        $r = new Reducer(new Normalizer(), $minEvents);
        $r->reduce($events);

        return $r;
    }

    public function testTheNumberOfDistinctMessagesUnderATemplateIsCounted(): void
    {
        $r = self::reduce(self::events('Payment declined for', 30, 3));

        self::assertSame([['template' => 'Payment declined for <*>', 'distinct' => 30]], $r->variability());
    }

    public function testAMessageRepeatedManyTimesIsNotVariable(): void
    {
        self::assertSame([], self::reduce(self::events('Connection reset by', 1, 200))->variability());
    }

    public function testAClusterBelowTheMinimumEventsIsNotListed(): void
    {
        self::assertSame([], self::reduce(self::events('Payment declined for', 2, 1))->variability());
    }

    public function testEachClusterIsListedOnce(): void
    {
        $r = self::reduce([...self::events('Payment declined for', 5, 1), ...self::events('Login failed for', 4, 1, Level::Error)]);
        $rows = $r->variability();
        usort($rows, static fn (array $a, array $b): int => strcmp($a['template'], $b['template']));

        self::assertSame([['template' => 'Login failed for <*>', 'distinct' => 4], ['template' => 'Payment declined for <*>', 'distinct' => 5]], $rows);
    }

    public function testItDescribesTheLastReduceOnly(): void
    {
        $r = new Reducer(new Normalizer(), 3);
        $r->reduce(self::events('Payment declined for', 10, 1));
        $r->reduce(self::events('Connection reset by', 1, 5));

        self::assertSame([], $r->variability());
    }
}
