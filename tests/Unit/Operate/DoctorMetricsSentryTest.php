<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Metrics\MetricResult;
use Watcher\Operate\Doctor;
use Watcher\Tests\Fakes\FakeIssueSource;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeMetricSource;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Spec 009 T046: `watcher check` rows for metrics and sentry; fixed tokens only, nothing billable, no row when the source is absent. */
final class DoctorMetricsSentryTest extends TestCase
{
    private function doctor(?FakeMetricSource $m, ?FakeIssueSource $i): Doctor
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-25T06:00:00Z'));
        $c = WatchConfig::fromArray([
            'service' => 'orders',
            'enabled' => true,
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.test'], 'stream_tags' => ['app' => 'orders']],
            'analysis' => ['api_key' => 'sk-secret-key', 'models' => ['triage' => 'm-small', 'analyze' => 'm-mid', 'escalate' => 'm-big'], ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/x']],
            'notify' => ['digest' => ['webhook' => 'https://hooks.slack.test/services/T0/B0/X']],
        ]);

        return new Doctor($c, new FakeLogSource(['error' => 1]), new InMemoryStateStore($clock), null, new FakeNotifier(), $clock, metrics: $m, issues: $i);
    }

    /** @return array<string, \Watcher\Operate\CheckResult> */
    private function by(Doctor $d): array
    {
        $out = [];
        foreach ($d->run()->results as $r) {
            $out[$r->name] = $r;
        }

        return $out;
    }

    public function testNoRowsWithoutTheSources(): void
    {
        self::assertSame(['config', 'source', 'provider_auth', 'models', 'state', 'notifier'], array_keys($this->by($this->doctor(null, null))));
    }

    public function testBothPassOnAnswersAndRunNoConfiguredQuery(): void
    {
        $m = FakeMetricSource::always(new MetricResult([1.0], 1));
        $by = $this->by($this->doctor($m, new FakeIssueSource([])));

        self::assertSame('pass', $by['metrics']->status);
        self::assertSame('pass', $by['sentry']->status);
        self::assertSame('vector(1)', $m->calls[0][1]);
    }

    public function testFailuresCarryOnlyTheFixedToken(): void
    {
        foreach (['unauthorized', 'timeout', 'rate_limited', 'too_large', 'bad_response', 'unreachable'] as $token) {
            $by = $this->by($this->doctor(FakeMetricSource::failing($token), FakeIssueSource::failing($token)));
            self::assertSame($token, $by['metrics']->reason);
            self::assertSame($token, $by['sentry']->reason);
            self::assertSame('fail', $by['metrics']->status);
        }
        $by = $this->by($this->doctor(FakeMetricSource::failing('GET https://x/?token=CANARY'), null));
        self::assertSame('unreachable', $by['metrics']->reason);
        self::assertSame('fail', $this->by($this->doctor(FakeMetricSource::always(new MetricResult([], 0)), null))['metrics']->status);
    }
}
