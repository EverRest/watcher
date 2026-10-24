<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Schedule;

use PHPUnit\Framework\TestCase;
use Watcher\Schedule\FatalGuard;
use Watcher\Tests\Fakes\SpyLogger;

final class FatalGuardTest extends TestCase
{
    public function testAFatalReleasesTheLockAndLogsOnlyTheErrorType(): void
    {
        $released = 0;
        $log = new SpyLogger();
        $g = new FatalGuard(function () use (&$released): void {
            ++$released;
        }, $log, 'svc');
        $g->arm();
        $g->handle(['type' => E_ERROR, 'message' => 'Allowed memory size exhausted SECRET', 'file' => 'x', 'line' => 1]);

        self::assertSame(1, $released);
        self::assertStringNotContainsString('SECRET', json_encode($log->records) ?: '');
        self::assertStringContainsString('E_ERROR', json_encode($log->records) ?: '');
    }

    public function testNothingHappensWhenDisarmedOrForANonFatal(): void
    {
        $released = 0;
        $g = new FatalGuard(function () use (&$released): void {
            ++$released;
        }, null, 'svc');
        $g->handle(['type' => E_ERROR, 'message' => 'm', 'file' => 'x', 'line' => 1]);
        $g->arm();
        $g->handle(['type' => E_WARNING, 'message' => 'm', 'file' => 'x', 'line' => 1]);
        $g->handle(null);
        $g->disarm();
        $g->handle(['type' => E_ERROR, 'message' => 'm', 'file' => 'x', 'line' => 1]);

        self::assertSame(0, $released);
    }
}
