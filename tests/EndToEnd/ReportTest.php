<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;
use Watcher\Report\Week;
use Watcher\Report\WeeklyReportRunner;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class ReportTest extends TestCase
{
    private string $events;

    private string $statePath;

    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'service' => 'orders',
            'environment' => 'int',
            'enabled' => true,
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki']],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0, 0], 'm2' => [3, 15, 0, 0], 'm3' => [5, 25, 0, 0]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => $this->statePath]],
            'notify' => [
                'digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/d'],
                'notice' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x'],
            ],
            'observe' => ['file' => ['enabled' => true, 'path' => $this->events, 'retention_days' => 8]],
            'report' => ['weekly' => ['enabled' => true]],
        ];
    }

    protected function setUp(): void
    {
        $this->events = sys_get_temp_dir() . '/watcher-rpt-' . bin2hex(random_bytes(4)) . '.jsonl';
        $this->statePath = sys_get_temp_dir() . '/watcher-st-' . bin2hex(random_bytes(4));
        copy(__DIR__ . '/../Fixtures/report/week-2026-W43.jsonl', $this->events);
    }

    protected function tearDown(): void
    {
        @unlink($this->events);
        @unlink($this->statePath);
    }

    public function testSentOncePerWeek(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-27T07:00:00Z'));
        $store = new InMemoryStateStore($clock);
        $notifier = new FakeNotifier();
        $c = \Watcher\Config\WatchConfig::fromArray($this->config());
        $runner = new WeeklyReportRunner(
            $c,
            $store,
            $clock,
            $notifier,
            new \Watcher\Report\EventLog($c->observe->file, 'orders', 'int'),
            new \Watcher\Report\WeeklyReport(),
            new \Watcher\Report\ReportRenderer(),
        );
        $week = Week::fromIso('2026-W43');
        self::assertSame('sent', $runner->run($week)->outcome);
        self::assertCount(1, $notifier->sent);
        self::assertSame('already_sent', $runner->run($week)->outcome);
    }

    public function testDryRunPrintsOnly(): void
    {
        $result = Factory::weeklyReport($this->config(), Week::fromIso('2026-W43'), true, new FrozenClock(new \DateTimeImmutable('2026-10-27T07:00:00Z')));
        self::assertSame('sent', $result->outcome);
        self::assertStringContainsString('week 2026-W43', (string) $result->text);
    }

    public function testNoRunsSendsNothing(): void
    {
        file_put_contents($this->events, '');
        $result = Factory::weeklyReport($this->config(), Week::fromIso('2026-W43'), false, new FrozenClock(new \DateTimeImmutable('2026-10-27T07:00:00Z')));
        self::assertSame('no_runs', $result->outcome);
    }
}
