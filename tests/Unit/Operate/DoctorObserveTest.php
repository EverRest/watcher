<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Observe\EventSanitizer;
use Watcher\Observe\SinkDispatcher;
use Watcher\Operate\CheckReport;
use Watcher\Operate\Doctor;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\RecordingEventSink;

final class DoctorObserveTest extends TestCase
{
    private const TOKEN = 'tok-observe-CANARYTOKEN1';
    private const URL = 'https://ingest.example.com/in/CANARYPATH';

    private string $dir;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/doctorobs-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-24T09:00:00Z'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    /** @param array<string, mixed> $observe */
    private function config(array $observe, bool $none = false): WatchConfig
    {
        return WatchConfig::fromArray([
            'service' => 'orders',
            'enabled' => true,
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.test'], 'stream_tags' => ['app' => 'orders']],
            'analysis' => ['api_key' => 'sk-secret-key', 'models' => ['triage' => 'm-small', 'analyze' => 'm-mid', 'escalate' => 'm-big'], ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/x']],
            'notify' => ['digest' => $none ? ['driver' => 'none'] : ['webhook' => 'https://hooks.slack.test/services/T0/B0/SECRETHOOK']],
            'observe' => $observe,
        ]);
    }

    private function doctor(WatchConfig $c, ?SinkDispatcher $probe = null): Doctor
    {
        return new Doctor($c, new FakeLogSource(['error' => 1]), new InMemoryStateStore($this->clock), null, new FakeNotifier(), $this->clock, observe: $probe);
    }

    /** @return array<string, \Watcher\Operate\CheckResult> */
    private static function byName(CheckReport $r): array
    {
        $out = [];
        foreach ($r->results as $c) {
            $out[$c->name] = $c;
        }

        return $out;
    }

    public function testNoSinkAddsNoCheck(): void
    {
        self::assertSame(['config', 'source', 'provider_auth', 'models', 'state', 'notifier'], array_keys(self::byName($this->doctor($this->config([]))->run())));
    }

    public function testEachEnabledSinkIsCheckedLocallyAndDriverNoneIsNamed(): void
    {
        $c = $this->config(['log' => ['enabled' => true], 'prometheus' => ['enabled' => true, 'dir' => $this->dir], 'file' => ['enabled' => true, 'path' => $this->dir . '/e.jsonl'], 'http' => ['enabled' => true, 'url' => self::URL, 'auth_token' => self::TOKEN]], none: true);

        $by = self::byName($this->doctor($c)->run());

        self::assertSame('none (dashboard-only)', $by['notifier']->reason);
        self::assertSame('pass', $by['observe']->status);
        self::assertSame('log ok, file ok, http ok, prometheus ok', $by['observe']->reason);
        self::assertSame([], glob($this->dir . '/*'), 'a plain check writes nothing');
    }

    public function testAnUnwritableTargetFailsNamingTheSinkAndKeyOnly(): void
    {
        $c = $this->config(['prometheus' => ['enabled' => true, 'dir' => $this->dir . '/missing'], 'file' => ['enabled' => true, 'path' => '/proc/nope/e.jsonl']]);

        $r = $this->doctor($c)->run();
        $observe = self::byName($r)['observe'];

        self::assertSame('fail', $observe->status);
        self::assertSame('file path invalid, prometheus dir invalid', $observe->reason);
        self::assertSame(1, $r->exitCode());
    }

    public function testPostTestFlushesOneCheckTestEventAndStoresNothing(): void
    {
        $sink = new RecordingEventSink();
        $probe = new SinkDispatcher(['log' => [$sink, true]], new EventSanitizer(new Redactor(new Normalizer())));
        $store = new InMemoryStateStore($this->clock);
        $d = new Doctor($this->config(['log' => ['enabled' => true]]), new FakeLogSource(['error' => 1]), $store, null, new FakeNotifier(), $this->clock, observe: $probe);

        $by = self::byName($d->run(true));

        self::assertCount(1, $sink->received);
        self::assertCount(1, $sink->received[0]);
        self::assertSame(['config_warning', 'check_test', 'orders'], [$sink->received[0][0]['event_type'], $sink->received[0][0]['code'], $sink->received[0][0]['service']]);
        self::assertSame('pass', $by['post_test_observe']->status);
        self::assertSame('log ok', $by['post_test_observe']->reason);
        $keys = array_keys($store->all());
        self::assertSame([], array_filter($keys, static fn (string $k): bool => str_starts_with($k, 'obs')), 'no counter is stored');
    }

    public function testPostTestNamesAFailedSinkWithoutDetail(): void
    {
        $probe = new SinkDispatcher(['log' => [new RecordingEventSink(new \RuntimeException('boom ' . self::URL)), true]], new EventSanitizer(new Redactor(new Normalizer())));

        $r = $this->doctor($this->config(['log' => ['enabled' => true]]), $probe)->run(true);

        $c = self::byName($r)['post_test_observe'];
        self::assertSame(['fail', 'log failed'], [$c->status, $c->reason]);
        self::assertStringNotContainsString('CANARY', $r->text() . (string) json_encode($r->toArray()));
    }
}
