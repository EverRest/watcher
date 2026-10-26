<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\DayRecord;
use Watcher\State\StateCodec;

/** The largest records the hygiene pass writes must fit what the Redis store's codec accepts (src/State/StateCodec.php). */
final class HygieneStateBudgetTest extends TestCase
{
    public function testADayRecordFullOfPunctuationIsReducedUntilItFitsAndIsMarkedSampled(): void
    {
        $sites = [];
        foreach (range(1, 500) as $i) {
            $sites[] = ['key' => sprintf('%016x', $i), 'id' => str_repeat('a', 120) . $i . '.php:12', 'kind' => 'frame', 'level' => 'warning', 'lines' => 9, 'any_hit' => 3,
                'cats' => ['email' => 3, 'host' => 1, 'token' => 1], 'long' => 0, 'trace' => 0, 'mismatch' => 0, 'first' => '2026-10-07T10:00:00+00:00', 'last' => '2026-10-07T11:00:00+00:00', 'label' => str_repeat('word, ', 40)];
        }
        $families = [];
        foreach (range(1, 1300) as $i) {
            $families[] = ['fp' => sprintf('%012x', $i), 'tpl' => str_repeat('failed [x], ', 20), 'th' => sprintf('%012x', $i), 'level' => 'error', 'n' => 5, 'first' => '2026-10-07T10:00:00+00:00', 'last' => '2026-10-07T11:00:00+00:00', 'tokens' => 10];
        }
        $variable = array_map(static fn (int $i): array => ['tpl' => str_repeat('a b, ', 30) . $i, 'positions' => 1, 'distinct_messages' => 30], range(1, 50));

        $big = new DayRecord('2026-10-07', 200000, false, 0, $sites, $families, ['text' => 100, 'json' => 5], $variable);
        self::assertFalse(StateCodec::fits(json_encode($big->toArray(), JSON_THROW_ON_ERROR)), 'the premise: punctuation in strings counts, and this record is over the codec budget');

        $fitted = $big->withinBudget(intdiv(StateCodec::MAX_BYTES, 2), intdiv(StateCodec::MAX_CONTAINERS, 2), intdiv(StateCodec::MAX_ELEMENTS, 2));

        $json = json_encode($fitted->toArray(), JSON_THROW_ON_ERROR);
        self::assertTrue(StateCodec::fits($json));
        self::assertLessThanOrEqual(StateCodec::MAX_CONTAINERS / 2, substr_count($json, '[') + substr_count($json, '{'));
        self::assertNotSame('', StateCodec::encode(json_decode($json, true, flags: JSON_THROW_ON_ERROR), 1024));
        self::assertTrue($fitted->sampled, 'a day that lost families is not a complete day');
        self::assertSame(200000, $fitted->events);
        self::assertSame($big->date, $fitted->date);
    }

    public function testAFullRegistryFitsTheCodecBudgets(): void
    {
        $registry = [];
        foreach (range(1, 2000) as $i) {
            $registry[sprintf('%016x', $i)] = ['id' => str_repeat('b', 200) . $i, 'kind' => 'frame', 'first' => '2026-09-20T06:00:00+00:00', 'last' => '2026-10-07T06:00:00+00:00', 'reportedAt' => '2026-10-01T06:00:00+00:00',
                'reportedCount' => 3, 'seen' => true, 'gonePending' => false, 'absent' => 0, 'absentOn' => null, 'goneSentAt' => null];
        }

        self::assertNotSame('', StateCodec::encode($registry, 1024), 'the real codec writes a registry at its entry cap');
    }
}
