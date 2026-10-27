<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;

final class FactoryTest extends TestCase
{
    public function testSourcesAndSlackKeepTheShortTimeout(): void
    {
        self::assertSame(['timeout' => 30, 'connect_timeout' => 10], Factory::httpOptions());
    }

    public function testTheModelClientWaitsLongerThanSourcesButWithinTheLockBudget(): void
    {
        // Two sequential attempts per model call must fit in a quarter of lock_ttl (default 900 s): 2 x 112 <= 225.
        self::assertSame(['timeout' => 112, 'connect_timeout' => 10], Factory::analyzerHttpOptions());
        self::assertSame(['timeout' => 112, 'connect_timeout' => 10], Factory::analyzerHttpOptions(900));
    }

    public function testTheModelTimeoutIsCappedAndFloored(): void
    {
        self::assertSame(180, Factory::analyzerHttpOptions(86400)['timeout']);
        self::assertSame(10, Factory::analyzerHttpOptions(30)['timeout']);
    }

}
