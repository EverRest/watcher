<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Select;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Select\LevelAnomaly;

final class LevelAnomalyTest extends TestCase
{
    public function testGrowthBelowReadLevelOnly(): void
    {
        $r = LevelAnomaly::levels(['info' => 1000, 'error' => 1], ['info' => 6000, 'error' => 100], 3.0, Level::Warning);
        self::assertSame([Level::Info], $r);
    }

    public function testExactFactorCountsBelowDoesNot(): void
    {
        self::assertSame([Level::Info], LevelAnomaly::levels(['info' => 100], ['info' => 300], 3.0, Level::Warning));
        self::assertSame([], LevelAnomaly::levels(['info' => 100], ['info' => 299], 3.0, Level::Warning));
    }

    public function testNoPreviousSnapshotMeansNoSecondPass(): void
    {
        self::assertSame([], LevelAnomaly::levels(null, ['info' => 99999], 3.0, Level::Warning));
    }

    public function testMissingOrZeroPreviousGrowsOnlyIfCurrentPositive(): void
    {
        $r = LevelAnomaly::levels(['debug' => 0], ['debug' => 5, 'info' => 7, 'notice' => 0], 3.0, Level::Warning);
        self::assertSame([Level::Debug, Level::Info], $r);
    }

    public function testReadLevelsAndAboveNeverReturned(): void
    {
        self::assertSame([], LevelAnomaly::levels(['warning' => 1, 'error' => 1], ['warning' => 50, 'error' => 50], 3.0, Level::Warning));
    }
}
