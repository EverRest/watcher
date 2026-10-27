<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\DryRunGuard;
use Watcher\Mcp\ToolError;

/** The dry-run guard admits a run only with the strict confirmation, one at a time, and never after a failed run. */
final class DryRunGuardTest extends TestCase
{
    public function testAConfirmedRunIsAdmittedOnceAtATime(): void
    {
        $g = new DryRunGuard();
        $g->admit(true);

        self::assertTrue($g->busy());
        $this->expectException(ToolError::class);
        $g->admit(true);
    }

    public function testTheConfirmationMustBeTheBooleanTrue(): void
    {
        foreach (['yes', 1, null] as $value) {
            try {
                (new DryRunGuard())->admit($value);
                self::fail('admitted without confirmation');
            } catch (ToolError $e) {
                self::assertSame("model_calls_not_confirmed", $e->errorCode);
            }
        }
    }

    public function testAfterAFailedRunNoFullRunIsAdmittedUntilRestart(): void
    {
        $g = new DryRunGuard();
        $g->admit(true);
        $g->end(true);

        $this->expectException(ToolError::class);
        $g->admit(true);
    }
}
