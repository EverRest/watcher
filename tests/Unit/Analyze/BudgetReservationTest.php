<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\BudgetReservation;
use Watcher\Analyze\CostCalculator;
use Watcher\Analyze\Spend;
use Watcher\Analyze\Usage;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class BudgetReservationTest extends TestCase
{
    private Spend $spend;

    protected function setUp(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-23T06:00:00+00:00'));
        $this->spend = new Spend(new InMemoryStateStore($clock), $clock, new DateTimeZone('UTC'), 'svc');
    }

    /** @param array<string, array<int, int|float>> $prices */
    private function reservation(float $budget, ?int $tokenLimit = null, array $prices = ['m' => [10, 10, 0, 0]]): BudgetReservation
    {
        return new BudgetReservation($this->spend, new CostCalculator($prices), $budget, $tokenLimit);
    }

    public function testWorstCaseMustFitInTheRemainingDailyBudget(): void
    {
        $this->spend->add(new Usage('m', 1, 1), 0.49);

        $r = $this->reservation(0.50); // $10 per million tokens: 400+400 tokens = $0.008, 1000+1000 = $0.02

        self::assertTrue($r->allows('m', 400, 400));
        self::assertFalse($r->allows('m', 1000, 1000));
    }

    public function testSpendBookedDuringTheRunShrinksTheRemainder(): void
    {
        $r = $this->reservation(0.50);
        self::assertTrue($r->allows('m', 20000, 20000)); // $0.40

        $this->spend->add(new Usage('m', 1, 1), 0.30);

        self::assertFalse($r->allows('m', 20000, 20000));
    }

    public function testExhaustedBudgetAllowsNothing(): void
    {
        $this->spend->add(new Usage('m', 1, 1), 0.50);

        self::assertFalse($this->reservation(0.50)->allows('m', 1, 1));
    }

    public function testCacheWritePriceIsTheWorstCaseForTheInput(): void
    {
        $r = $this->reservation(0.01, null, ['m' => [1, 1, 0, 100]]); // cache write: $100 per million

        self::assertFalse($r->allows('m', 1000, 0), '1000 input tokens at the cache-write price is $0.10');
    }

    public function testTokenLimitCountsInputAndOutput(): void
    {
        $this->spend->add(new Usage('m', 900, 0), 0.0);

        $r = $this->reservation(100.0, 1000);

        self::assertTrue($r->allows('m', 50, 50));
        self::assertFalse($r->allows('m', 50, 51));
    }

    public function testModelWithoutAPriceCostsNothingButStillCountsTokens(): void
    {
        $r = $this->reservation(0.0, 100, []);

        self::assertTrue($r->allows('other', 40, 40));
        self::assertFalse($r->allows('other', 60, 60));
    }
}
