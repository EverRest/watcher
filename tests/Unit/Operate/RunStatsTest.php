<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Usage;
use Watcher\Operate\RunStats;

final class RunStatsTest extends TestCase
{
    public function testStartsEmptyAndHoldsOnlyNumbers(): void
    {
        $s = new RunStats();

        self::assertSame([0, 0, 0, 0], [$s->eventsRead, $s->groups, $s->candidates, $s->findings]);
        self::assertSame(['triage' => 0, 'analyze' => 0, 'escalate' => 0], $s->calls);
        self::assertSame(0.0, $s->costUsd);
        self::assertNull($s->durationMs);
        self::assertNull($s->pages);
    }

    public function testAddUsageAccumulatesTokensCostAndTheCallOfThatStep(): void
    {
        $s = new RunStats();
        $s->addUsage('triage', new Usage('m', 100, 10, 40, 0, 0.01));
        $s->addUsage('analyze', new Usage('m', 200, 20, 0, 5, 0.02));
        $s->addUsage('analyze', new Usage('m', 1, 1, 1, 0, 0.0));

        self::assertSame(['triage' => 1, 'analyze' => 2, 'escalate' => 0], $s->calls);
        self::assertSame([301, 31, 41], [$s->tokensIn, $s->tokensOut, $s->tokensCached]);
        self::assertEqualsWithDelta(0.03, $s->costUsd, 1e-9);
    }

    public function testUnknownStepIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // @phpstan-ignore argument.type
        (new RunStats())->addUsage('free text', new Usage('m', 1, 1));
    }
}
