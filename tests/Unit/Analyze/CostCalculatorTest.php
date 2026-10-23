<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\CostCalculator;
use Watcher\Analyze\UnknownModelPrice;
use Watcher\Analyze\Usage;

final class CostCalculatorTest extends TestCase
{
    public function testPricesEachTokenKindByItsOwnRate(): void
    {
        $calc = new CostCalculator(['m' => [3.0, 15.0, 0.3, 3.75]]);

        $cost = $calc->cost(new Usage('m', 1_000_000, 500_000, 2_000_000, 100_000));

        // 3 + 7.5 + 0.6 + 0.375
        self::assertEqualsWithDelta(11.475, $cost, 1e-9);
    }

    public function testZeroUsageCostsNothing(): void
    {
        self::assertSame(0.0, (new CostCalculator(['m' => [1, 2, 3, 4]]))->cost(new Usage('m', 0, 0)));
    }

    public function testUnknownModelThrows(): void
    {
        $this->expectException(UnknownModelPrice::class);
        (new CostCalculator(['m' => [1, 2, 3, 4]]))->cost(new Usage('other', 1, 1));
    }

    public function testPricesByTheRequestedIdEvenWhenTheApiReturnsADatedOne(): void
    {
        $calc = new CostCalculator(['alias' => [1.0, 0.0, 0.0, 0.0]]);

        self::assertEqualsWithDelta(1.0, $calc->cost(new Usage('alias', 1_000_000, 0, returnedModel: 'alias-20261001')), 1e-9);
    }
}
