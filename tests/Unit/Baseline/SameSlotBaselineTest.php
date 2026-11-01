<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Baseline;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Baseline\BaselineResolver;
use Watcher\Reduce\Fingerprinter;
use Watcher\Schedule\RunGate;
use Watcher\Schedule\Window;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Spec 014 US6: same-slot baselines compare an hourly window with the same wall-clock slot a day or a week earlier. */
final class SameSlotBaselineTest extends TestCase
{
    private InMemoryStateStore $state;

    protected function setUp(): void
    {
        $this->state = new InMemoryStateStore(new FrozenClock(new DateTimeImmutable('2026-10-28T14:00:00+00:00')));
    }

    private function resolver(string $mode): BaselineResolver
    {
        $zone = new DateTimeZone('UTC');
        $gate = RunGate::fromSchedule(['timezone' => 'UTC', 'every' => 'PT1H', 'period_seconds' => 3600], $zone);
        $baseline = ['mode' => $mode, 'windows' => 7, 'exclude' => [], 'since' => null];

        return new BaselineResolver($this->state, $gate, 'svc', $baseline, $zone, 3.0);
    }

    private function window(): Window
    {
        return Window::endingAt(new DateTimeImmutable('2026-10-28 14:00', new DateTimeZone('UTC')), 'PT1H');
    }

    /** @param array<string, int> $groups */
    private function snap(string $end, array $groups): void
    {
        $e = new DateTimeImmutable($end, new DateTimeZone('UTC'));
        $doc = [
            'groups' => $groups,
            'totals' => [],
            'fpv' => Fingerprinter::FPV,
            'start' => $e->modify('-1 hour')->format('Y-m-d\TH:i:s\Z'),
        ];
        $this->state->put(Keys::snapshot('svc', $e->format('Y-m-d\TH:i:s\Z')), $doc, 86400 * 60);
    }

    public function testYesterdaySlot(): void
    {
        $this->snap('2026-10-27T14:00:00Z', ['fp' => 4]);
        $this->snap('2026-10-27T13:00:00Z', ['fp' => 99]); // the previous hour must not be used

        $c = $this->resolver('same_slot_yesterday')->resolve($this->window(), ['fp'], null, null);

        self::assertSame('same_slot_yesterday', $c->usedMode);
        self::assertNull($c->reason);
        self::assertSame(4.0, $c->values['fp']);
        self::assertSame('2026-10-27T14:00:00+00:00', $c->ends[0]->format('c'));
    }

    public function testLastWeekSlot(): void
    {
        $this->snap('2026-10-21T14:00:00Z', ['fp' => 7]);
        $this->snap('2026-10-27T14:00:00Z', ['fp' => 1]);

        $c = $this->resolver('same_slot_last_week')->resolve($this->window(), ['fp'], null, null);

        self::assertSame('same_slot_last_week', $c->usedMode);
        self::assertSame(7.0, $c->values['fp']);
        self::assertCount(1, $c->ends); // one slot only: the day-before snapshot must not be a candidate
        self::assertSame('2026-10-21T14:00:00+00:00', $c->ends[0]->format('c'));
    }

    public function testMissingSlotFallsBackToPreviousWindow(): void
    {
        $prevEnd = new DateTimeImmutable('2026-10-28 13:00', new DateTimeZone('UTC'));
        $prev = ['groups' => ['fp' => 2], 'totals' => [], 'fpv' => Fingerprinter::FPV];

        $c = $this->resolver('same_slot_yesterday')->resolve($this->window(), ['fp'], $prev, $prevEnd);

        self::assertSame('same_slot_yesterday', $c->requestedMode);
        self::assertSame('previous', $c->usedMode);
        self::assertSame('slot_missing', $c->reason);
        self::assertSame(2.0, $c->values['fp']);
    }
}
