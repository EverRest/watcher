<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Select;

use PHPUnit\Framework\TestCase;
use Watcher\Select\CandidateReason;

final class CandidateReasonTest extends TestCase
{
    public function testWeightTableListsEveryCase(): void
    {
        $expected = ['watch_rule' => 6, 'level' => 5, 'metric_anomaly' => 5, 'new' => 4, 'known_grown' => 4, 'grown' => 3, 'level_anomaly' => 2, 'top_volume' => 1];
        $actual = [];
        foreach (CandidateReason::cases() as $case) {
            $actual[$case->value] = $case->weight();
        }

        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
    }
}
