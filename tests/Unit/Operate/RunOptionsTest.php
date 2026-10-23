<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use PHPUnit\Framework\TestCase;
use Watcher\Operate\RunOptions;

final class RunOptionsTest extends TestCase
{
    public function testHelpers(): void
    {
        $d = RunOptions::default();
        self::assertFalse($d->dryRun || $d->estimate || $d->json);
        $r = RunOptions::dryRun(estimate: true, json: true);
        self::assertTrue($r->dryRun && $r->estimate && $r->json);
        self::assertFalse(RunOptions::dryRun()->estimate);
    }

    public function testEstimateWithoutDryRunIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RunOptions(dryRun: false, estimate: true);
    }
}
