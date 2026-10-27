<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use PHPUnit\Framework\TestCase;
use Watcher\Operate\RunStats;

final class RunStatsTest extends TestCase
{
    public function testStartsEmptyAndHoldsOnlyNumbers(): void
    {
        $s = new RunStats();

        self::assertSame([0, 0, 0, 0], [$s->eventsRead, $s->groups, $s->candidates, $s->findings]);
        self::assertSame(['triage' => 0, 'analyze' => 0, 'escalate' => 0], $s->calls);
        self::assertNull($s->durationMs);
        self::assertNull($s->pages);
    }

    public function testAddCallCountsTheCallOfThatStep(): void
    {
        $s = new RunStats();
        $s->addCall('triage');
        $s->addCall('analyze');
        $s->addCall('analyze');

        self::assertSame(['triage' => 1, 'analyze' => 2, 'escalate' => 0], $s->calls);
    }

    public function testUnknownStepIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RunStats())->addCall('free text');
    }
}
