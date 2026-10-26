<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\DryRunGuard;
use Watcher\Mcp\ToolError;

final class DryRunGuardTest extends TestCase
{
    private function guard(float $spentUsd = 0.0, float $budget = 0.5, ?int $tokenLimit = null, int $spentTokens = 0): DryRunGuard
    {
        return new DryRunGuard(static fn (): array => ['usd' => $spentUsd, 'tokens' => $spentTokens], $budget, $tokenLimit);
    }

    private function refusal(DryRunGuard $g, mixed $confirm, FakePipeline $p): string
    {
        try {
            $g->admit($confirm, $p);
        } catch (ToolError $e) {
            self::assertSame(0, $p->runs, 'a refusal must make zero model calls');

            return $e->errorCode;
        }
        self::fail('admitted');
    }

    /** @return array<string, array{mixed}> */
    public static function notConfirmed(): array
    {
        return ['absent' => [null], 'string true' => ['true'], 'one' => [1], 'false' => [false], 'array' => [[true]]];
    }

    /** @dataProvider notConfirmed */
    public function testOnlyStrictTrueConfirms(mixed $confirm): void
    {
        self::assertSame('cost_not_confirmed', $this->refusal($this->guard(), $confirm, new FakePipeline()));
    }

    public function testExhaustedSpendIsRefusedBeforeAnyEstimate(): void
    {
        $p = new FakePipeline();
        self::assertSame('daily_limit_reached', $this->refusal($this->guard(0.5), true, $p));
        self::assertSame(0, $p->estimates);
        self::assertSame('daily_limit_reached', $this->refusal($this->guard(0.0, 0.5, 1000, 1000), true, $p));
    }

    public function testEstimateAboveTheRemainderIsRefusedWithTheFigures(): void
    {
        $g = $this->guard(0.45);
        try {
            $g->admit(true, new FakePipeline());
            self::fail();
        } catch (ToolError $e) {
            self::assertSame('would_exceed_daily_limit', $e->errorCode);
            self::assertEqualsWithDelta(0.10, $e->extra['estimate_usd'], 1e-9);
            self::assertEqualsWithDelta(0.05, $e->extra['remaining_usd'], 1e-9);
        }
    }

    public function testTheEscalationAllowanceIsAdded(): void
    {
        // triage + analyze = 0.06 fits in 0.08; the escalation allowance 0.04 does not
        self::assertSame('would_exceed_daily_limit', $this->refusal($this->guard(0.42), true, new FakePipeline()));
        $p = new FakePipeline(allowance: ['usd' => 0.0, 'tokens' => 0]);
        $r = $this->guard(0.42)->admit(true, $p);
        self::assertEqualsWithDelta(0.06, $r['usd'], 1e-9);
    }

    public function testAnUnpricedEscalationModelIsRefused(): void
    {
        self::assertSame('would_exceed_daily_limit', $this->refusal($this->guard(), true, new FakePipeline(allowance: null)));
    }

    public function testTheTokenLimitIsHandledTheSameWay(): void
    {
        // steps 7000 tokens + escalation 1000 = 8000
        self::assertSame('would_exceed_daily_limit', $this->refusal($this->guard(0.0, 0.5, 7999, 0), true, new FakePipeline()));
        self::assertSame(['usd' => 0.10, 'tokens' => 8000], array_map(static fn (float|int $v): float|int => is_float($v) ? round($v, 6) : $v, $this->guard(0.0, 0.5, 8000, 0)->admit(true, new FakePipeline(allowance: ['usd' => 0.04, 'tokens' => 1000]))));
    }

    public function testRunsNeverExceedTheStartingRemainder(): void
    {
        $g = $this->guard(0.0, 0.35);
        $p = new FakePipeline();
        $allowed = 0.0;
        for ($i = 0; $i < 10; ++$i) {
            try {
                $b = $g->admit(true, $p);
                $g->enter($b);
                $g->end();
                $allowed += $b['usd'];
            } catch (ToolError $e) {
                self::assertContains($e->errorCode, ['would_exceed_daily_limit', 'daily_limit_reached']);
            }
        }
        self::assertLessThanOrEqual(0.35 + 1e-9, $allowed);
        self::assertEqualsWithDelta(0.30, $allowed, 1e-9); // three runs of 0.10
        self::assertEqualsWithDelta(0.05, $g->spendToday()['remaining_usd'], 1e-9);
    }

    public function testTheEscalationTokensCountAtTheBoundary(): void
    {
        self::assertSame(8000, $this->guard(0.0, 0.5, 8000, 0)->admit(true, new FakePipeline())['tokens']);
        self::assertSame('would_exceed_daily_limit', $this->refusal($this->guard(0.0, 0.5, 7999, 0), true, new FakePipeline()));
        // the same limit passes when the escalation step carries no tokens: it is the escalation bound that refuses
        self::assertSame(7000, $this->guard(0.0, 0.5, 7999, 0)->admit(true, new FakePipeline(allowance: ['usd' => 0.04, 'tokens' => 0]))['tokens']);
    }

    public function testAnUnknownEscalationBoundIsRefused(): void
    {
        self::assertSame('would_exceed_daily_limit', $this->refusal($this->guard(), true, new FakePipeline(allowance: null)));
    }

    public function testAdmitReservesBeforeReturningSoASecondCallIsBusy(): void
    {
        $g = $this->guard();
        $g->admit(true, new FakePipeline());
        self::assertSame('busy', $this->refusal($g, true, new FakePipeline()));
        $g->end();
    }

    public function testAFailedRunBooksTheBoundAndBlocksFurtherRuns(): void
    {
        $g = $this->guard();
        $b = $g->admit(true, new FakePipeline());
        $g->enter($b);
        $g->end(failed: true);
        self::assertEqualsWithDelta(0.5 - $b['usd'], $g->spendToday()['remaining_usd'], 1e-9);
        self::assertSame('daily_limit_reached', $this->refusal($g, true, new FakePipeline()));
    }

    public function testBusyWhileRunningAndClearedAfterAnException(): void
    {
        $g = $this->guard();
        $g->admit(true, new FakePipeline());
        self::assertSame('busy', $this->refusal($g, true, new FakePipeline()));
        $g->end();
        $g->admit(true, new FakePipeline());
        $g->end();
        self::assertFalse($g->busy());
    }
}
