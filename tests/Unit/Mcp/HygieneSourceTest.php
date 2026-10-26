<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\HygieneSource;
use Watcher\Mcp\NoHygieneSource;
use Watcher\Mcp\Tools\HygieneTool;

final class HygieneSourceTest extends TestCase
{
    public function testTheDefaultSourceIsEmptyAndTheToolSaysNotEnabled(): void
    {
        self::assertNull((new NoHygieneSource())->latest());
        self::assertSame(['available' => false, 'reason' => 'hygiene_not_enabled'], (new HygieneTool(new NoHygieneSource()))->call([]));
    }

    public function testASourceWithAReportIsAvailable(): void
    {
        $src = new class () implements HygieneSource {
            public function latest(): array
            {
                return ['counts' => 3];
            }
        };
        self::assertSame(['available' => true, 'report' => ['counts' => 3]], (new HygieneTool($src))->call([]));
    }
}
