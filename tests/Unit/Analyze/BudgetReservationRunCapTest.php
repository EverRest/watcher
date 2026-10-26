<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\BudgetReservation;
use Watcher\Analyze\CostCalculator;
use Watcher\Analyze\Spend;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class BudgetReservationRunCapTest extends TestCase
{
    public function testRunBudgetBlocksWhenShareWouldBeExceeded(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-08T12:00:00+00:00'));
        $spend = new Spend(new InMemoryStateStore($clock), $clock, new \DateTimeZone('UTC'), 'svc');
        $reservation = new BudgetReservation($spend, new CostCalculator(['m' => [10, 10, 0, 0]]), 1.0, null, null, 0.04);
        $reservation->addRunSpend(0.03);
        self::assertFalse($reservation->allows('m', 5000, 5000));
    }
}
