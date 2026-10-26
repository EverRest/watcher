<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Usage;
use Watcher\RunOutcome;
use Watcher\RunResult;

final class RunResultTest extends TestCase
{
    public function testNonFailureOutcomesExitZero(): void
    {
        foreach (RunOutcome::cases() as $outcome) {
            if ($outcome === RunOutcome::Failed || $outcome === RunOutcome::InvalidConfig) {
                continue;
            }
            self::assertSame(0, (new RunResult($outcome))->exitCode(), $outcome->value);
        }
    }

    public function testFailedExitsOneAndKeepsReason(): void
    {
        $result = new RunResult(RunOutcome::Failed, 'analyzer unavailable');

        self::assertSame(1, $result->exitCode());
        self::assertSame('analyzer unavailable', $result->failureReason);
    }

    public function testInvalidConfigExitsTwo(): void
    {
        $result = RunResult::invalidConfig('missing log path');

        self::assertSame(RunOutcome::InvalidConfig, $result->outcome);
        self::assertSame('missing log path', $result->failureReason);
        self::assertSame(2, $result->exitCode());
    }

    public function testUsageIsNullUnlessAModelWasCalled(): void
    {
        $usage = new Usage('m', 10, 5, 0, 0, 0.01);

        self::assertNull((new RunResult(RunOutcome::Quiet))->usage);
        self::assertSame($usage, (new RunResult(RunOutcome::Reported, null, $usage))->usage);
        self::assertNull(RunResult::invalidConfig('x')->usage);
    }

    public function testOutcomeValues(): void
    {
        self::assertSame(
            ['skipped_not_due', 'skipped_locked', 'skipped_done', 'baselined', 'quiet', 'reported', 'failed', 'invalid_config', 'disabled', 'capped'],
            array_map(static fn (RunOutcome $o): string => $o->value, RunOutcome::cases()),
        );
    }

    public function testSummaryIsOneLineWithOutcomeReasonAndCost(): void
    {
        self::assertSame('outcome=quiet', (new RunResult(RunOutcome::Quiet))->summary());
        self::assertSame(
            'outcome=failed reason="source failed: loki 401 Unauthorized"',
            (new RunResult(RunOutcome::Failed, 'source failed: loki 401 Unauthorized'))->summary(),
        );

        $usage = new \Watcher\Analyze\Usage('m', 1200, 340, 100, 0, 0.0412);
        self::assertSame(
            'outcome=reported tokens_in=1200 tokens_out=340 tokens_cached=100 cost_usd=0.0412',
            (new RunResult(RunOutcome::Reported, null, $usage))->summary(),
        );
    }

    public function testSummaryNeverContainsLineBreaksOrQuotesFromTheReason(): void
    {
        $line = (new RunResult(RunOutcome::Failed, "bad\nreason \"x\""))->summary();

        self::assertStringNotContainsString("\n", $line);
        self::assertSame('outcome=failed reason="bad reason \'x\'"', $line);
    }
}
