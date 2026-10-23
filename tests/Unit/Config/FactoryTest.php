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

    public function testTheModelClientWaitsLongerThanSources(): void
    {
        self::assertSame(['timeout' => 180, 'connect_timeout' => 10], Factory::analyzerHttpOptions());
    }
}
