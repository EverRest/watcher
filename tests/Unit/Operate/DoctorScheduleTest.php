<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Operate\CheckResult;
use Watcher\Operate\Doctor;
use Watcher\Operate\ModelCatalog;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Spec 014 contract: `watcher check` warns about schedule shapes that read less or more than they should; warnings never fail. */
final class DoctorScheduleTest extends TestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-24T09:00:00Z'));
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function report(array $schedule): \Watcher\Operate\CheckReport
    {
        $config = WatchConfig::fromArray([
            'service' => 'orders',
            'enabled' => true,
            'schedule' => $schedule,
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.test', 'auth' => ['type' => 'bearer', 'token' => 'T']], 'stream_tags' => ['app' => 'orders']],
            'analysis' => [
                'api_keys' => ['gemini' => 'sk-test'],
                'models' => ['triage' => 'm-small', 'analyze' => 'm-mid', 'escalate' => 'm-big'],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/x']],
            'notify' => ['digest' => ['webhook' => 'https://hooks.slack.test/services/T0/B0/X']],
        ]);
        $catalog = new class () implements ModelCatalog {
            public function probe(): array
            {
                return ['bearer' => 200, 'x-api-key' => 200];
            }

            public function modelIds(): array
            {
                return ['m-small', 'm-mid', 'm-big'];
            }
        };

        return (new Doctor($config, new FakeLogSource(['error' => 1]), new InMemoryStateStore($this->clock), $catalog, new FakeNotifier(), $this->clock))->run();
    }

    private static function row(\Watcher\Operate\CheckReport $report, string $name): ?CheckResult
    {
        foreach ($report->results as $r) {
            if ($r->name === $name) {
                return $r;
            }
        }

        return null;
    }

    public function testWindowShorterThanThePeriodWarnsAndStillPasses(): void
    {
        $report = $this->report(['every' => 'PT1H', 'window' => 'PT30M', 'run_at' => null]);

        $row = self::row($report, 'schedule');
        self::assertNotNull($row);
        self::assertSame('warn', $row->status);
        self::assertStringContainsString('schedule.window (PT30M) is shorter than the period (PT1H)', $row->reason);
        self::assertSame(0, $report->exitCode());
    }

    public function testWindowLongerThanThePeriodWarns(): void
    {
        $row = self::row($this->report(['every' => 'PT1H', 'window' => 'PT3H', 'run_at' => null]), 'schedule');

        self::assertNotNull($row);
        self::assertStringContainsString('is longer than the period (PT1H): windows overlap', $row->reason);
    }

}
