<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Fleet;

use PHPUnit\Framework\TestCase;
use Watcher\Fleet\FleetCheck;
use Watcher\Fleet\FleetConfig;
use Watcher\Fleet\ServiceEntry;
use Watcher\Operate\CheckReport;
use Watcher\Operate\CheckResult;

final class FleetCheckTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $services
     * @param array<string, string> $fail check name => reason, failing for the named service
     */
    private static function check(array $services, array $fail = [], ?string $failService = null): FleetCheck
    {
        $fleet = FleetConfig::fromArray(['fleet' => 'platform', 'services' => $services]);
        $report = static function (ServiceEntry $e) use ($fail, $failService): CheckReport {
            $r = [];
            foreach (['source', 'state', 'notifier'] as $name) {
                $r[] = $e->name() === $failService && isset($fail[$name]) ? CheckResult::fail($name, $fail[$name]) : CheckResult::pass($name);
            }

            return new CheckReport($r);
        };

        return new FleetCheck($fleet, $report);
    }

    /** @return list<string> */
    private static function names(CheckReport $r): array
    {
        return array_map(static fn (CheckResult $c): string => $c->name, $r->results);
    }

    public function testPerServiceChecksOneSharedStateCheckAndTheResolvedOrder(): void
    {
        $b = FleetConfigTest::service('bff-a', ['dependencies' => ['api' => ['stream_tags' => ['app' => 'api']]]]);
        $report = self::check([$b, FleetConfigTest::service('api')])->run(false);

        self::assertSame(['order', 'state', 'api/source', 'api/notifier', 'bff-a/source', 'bff-a/notifier'], self::names($report));
        self::assertSame('api, bff-a', $report->results[0]->reason);
        self::assertSame(0, $report->exitCode());
    }

    public function testAFailureNamesTheService(): void
    {
        $report = self::check([FleetConfigTest::service('api'), FleetConfigTest::service('bff-a')], ['source' => 'HTTP 502'], 'bff-a')->run(false);

        self::assertSame(1, $report->exitCode());
        self::assertStringContainsString('bff-a/source', $report->text());
        self::assertStringContainsString('HTTP 502', $report->text());
    }

    public function testSharedStateFailureIsReportedOnce(): void
    {
        $report = self::check([FleetConfigTest::service('api'), FleetConfigTest::service('bff-a')], ['state' => 'unavailable'], 'api')->run(false);

        self::assertCount(1, array_filter($report->results, static fn (CheckResult $c): bool => $c->status === 'fail'));
        self::assertSame(1, $report->exitCode());
    }

    public function testADependencyProvidedByNoServiceIsAWarningNotAFailure(): void
    {
        $a = FleetConfigTest::service('api', ['dependencies' => ['payments-db' => ['stream_tags' => ['app' => 'db']]]]);

        $report = self::check([$a])->run(false);

        $warn = array_values(array_filter($report->results, static fn (CheckResult $c): bool => $c->name === 'dependencies'));
        self::assertCount(1, $warn);
        self::assertSame('pass', $warn[0]->status);
        self::assertStringContainsString("warning: api depends on 'payments-db', provided by no fleet service", $warn[0]->reason);
        self::assertSame(0, $report->exitCode());
    }

    public function testInvalidFleetIsTheFailedConfigCheck(): void
    {
        $report = FleetCheck::invalid('services[1] (bff-a): selection.growth_factor must be greater than 1');

        self::assertSame(1, $report->exitCode());
        self::assertSame('config', $report->results[0]->name);
    }
}
