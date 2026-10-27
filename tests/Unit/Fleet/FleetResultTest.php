<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Fleet;

use PHPUnit\Framework\TestCase;
use Watcher\Fleet\FleetResult;
use Watcher\Fleet\ServiceResult;
use Watcher\Operate\RunStats;
use Watcher\Operate\RunSummaryData;
use Watcher\RunOutcome;
use Watcher\RunResult;

final class FleetResultTest extends TestCase
{
    private static function ran(string $service, RunOutcome $o, ?string $reason = null, ?RunSummaryData $data = null): ServiceResult
    {
        return ServiceResult::ran($service, new RunResult($o, $reason, $data));
    }

    /** @param list<ServiceResult> $services */
    private static function fleet(array $services, ?bool $correlatedPosted = null, ?bool $combinedPosted = null): FleetResult
    {
        return new FleetResult('platform', $services, [], $correlatedPosted, $combinedPosted);
    }

    public function testExitCodeIsTheWorstOfTheServices(): void
    {
        self::assertSame(0, self::fleet([self::ran('a', RunOutcome::Reported), self::ran('b', RunOutcome::Quiet)])->exitCode());
        self::assertSame(1, self::fleet([self::ran('a', RunOutcome::Reported), self::ran('b', RunOutcome::Failed, 'source_unavailable')])->exitCode());
        self::assertSame(1, self::fleet([self::ran('a', RunOutcome::Reported), ServiceResult::skippedTime('b')])->exitCode());
        self::assertSame(2, self::fleet([self::ran('a', RunOutcome::Failed), self::ran('b', RunOutcome::InvalidConfig)])->exitCode());
    }

    public function testNotDueDoneAndLockedAreExitZero(): void
    {
        $r = self::fleet([self::ran('a', RunOutcome::SkippedNotDue), self::ran('b', RunOutcome::SkippedDone), self::ran('c', RunOutcome::SkippedLocked), self::ran('d', RunOutcome::Baselined)]);

        self::assertSame(0, $r->exitCode());
    }

    public function testAFailedFleetPostIsExitOne(): void
    {
        self::assertSame(1, self::fleet([self::ran('a', RunOutcome::Quiet)], false)->exitCode());
        self::assertSame(1, self::fleet([self::ran('a', RunOutcome::Quiet)], null, false)->exitCode());
        self::assertSame(0, self::fleet([self::ran('a', RunOutcome::Quiet)], true, true)->exitCode());
    }

    public function testJsonLineHasTheContractFieldsAndNoGroupContent(): void
    {
        $stats = new RunStats();
        $stats->addCall('analyze');
        $stats->findings = 2;
        $data = new RunSummaryData(RunOutcome::Reported, 'api', null, null, null, $stats);
        $r = self::fleet([self::ran('api', RunOutcome::Reported, null, $data), self::ran('bff-a', RunOutcome::Quiet), ServiceResult::skippedTime('bff-b')]);

        $line = $r->toJson();
        self::assertStringNotContainsString("\n", $line);
        $j = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('platform', $j['fleet']);
        self::assertSame(1, $j['exit']);
        self::assertSame(['service' => 'api', 'outcome' => 'reported', 'reason' => null, 'findings' => 2], $j['services'][0]);
        self::assertSame(['service' => 'bff-a', 'outcome' => 'quiet', 'reason' => null, 'findings' => 0], $j['services'][1]);
        self::assertSame('skipped_time', $j['services'][2]['outcome']);
        self::assertSame(0, $j['correlated']);
        self::assertNull($j['correlated_posted']);
        self::assertSame(['fleet', 'exit', 'services', 'correlated', 'correlated_posted'], array_keys($j));
    }

    public function testReasonIsSanitisedLikeTheSingleRunSummary(): void
    {
        $r = self::fleet([self::ran('a', RunOutcome::Failed, 'it said "boom" at https://x.test/hook/ABCDEFGHIJKLMNOPQRSTUVWXYZ0123')]);
        $j = json_decode($r->toJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('unspecified', $j['services'][0]['reason']);

        $ok = json_decode(self::fleet([self::ran('a', RunOutcome::Failed, 'source_unavailable')])->toJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('source_unavailable', $ok['services'][0]['reason']);

        $line = self::fleet([self::ran('a', RunOutcome::Failed, "two\nlines \"q\"")])->lines()[0];
        self::assertStringNotContainsString("\n", $line);
        self::assertStringNotContainsString('"q"', $line);
    }

    public function testHumanLines(): void
    {
        $r = self::fleet([self::ran('a', RunOutcome::Reported), self::ran('b', RunOutcome::Failed, 'x'), ServiceResult::skippedTime('c')]);

        $lines = $r->lines();
        self::assertSame('a: outcome=reported', $lines[0]);
        self::assertStringStartsWith('b: outcome=failed', $lines[1]);
        self::assertSame('c: outcome=skipped_time', $lines[2]);
        self::assertSame('fleet platform: services=3 failed=1 skipped_time=1 correlated=0', $lines[3]);
    }

    public function testInvalidFleetJson(): void
    {
        self::assertSame('{"fleet":null,"exit":2,"error":"invalid_config"}', FleetResult::invalidJson());
    }
}
