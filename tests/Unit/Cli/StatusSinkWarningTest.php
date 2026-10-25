<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Cli;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;
use Watcher\Operate\HeartbeatStatus;

final class StatusSinkWarningTest extends TestCase
{
    private static function ok(): HeartbeatStatus
    {
        $now = new DateTimeImmutable('2026-10-24T09:00:00Z');

        return new HeartbeatStatus('ok', ['outcome' => 'reported'], 60, 129600, $now, $now, $now);
    }

    public function testStatusPrintsTheSinkWarningAndKeepsItsExitCode(): void
    {
        $status = self::ok()->withWarnings(['sink_failed:http']);

        self::assertStringContainsString("warning: sink_failed:http\n", $status->text('orders'));
        self::assertSame(0, $status->exitCode());
        self::assertSame(['sink_failed:http'], $status->toArray('orders')['warnings']);
    }

    public function testWithoutWarningsTheOutputIsUnchanged(): void
    {
        $status = self::ok();

        self::assertStringNotContainsString('warning:', $status->text('orders'));
        self::assertArrayNotHasKey('warnings', $status->toArray('orders'));
        self::assertSame($status, $status->withWarnings([]));
    }

    public function testTheLogSinkWritesToStderrUnderJson(): void
    {
        self::assertSame('php://stdout', Factory::logTarget('stdout', false));
        self::assertSame('php://stderr', Factory::logTarget('stdout', true), 'the --json summary stays one parseable line on stdout');
        self::assertSame('php://stderr', Factory::logTarget('stderr', false));
    }
}
