<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Operate\Doctor;
use Watcher\Reduce\Normalizer;
use Watcher\Source\Kubernetes\KubeApi;
use Watcher\Source\KubernetesEventsSource;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Spec 008 US3 / FR-016: `check` reads the cluster with GETs only and says what it saw in a fixed vocabulary. */
final class DoctorEventsTest extends TestCase
{
    private const TOKEN = 'BEARER-TEST-TOKEN-0123456789';

    /** @var list<array<string, mixed>> */
    private array $history = [];

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private static function config(array $patch = []): array
    {
        return array_replace_recursive([
            'service' => 'orders',
            'enabled' => true,
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.test'], 'stream_tags' => ['app' => 'orders']],
            'analysis' => [
                'api_key' => 'sk-ant-SECRET-KEY',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],

            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/x']],
            'notify' => ['digest' => ['webhook' => 'https://hooks.slack.test/services/T0/B0/SECRETHOOK']],
            'kubernetes' => ['namespaces' => ['shop', 'billing'], 'api' => ['server' => 'https://k8s.example', 'token' => self::TOKEN]],
            'events' => ['driver' => 'kubernetes'],
        ], $patch);
    }

    /**
     * @param array<string, mixed> $config
     * @param list<Response> $queue
     */
    private function doctor(array $config, array $queue): Doctor
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-24T09:00:00Z'));
        $c = WatchConfig::fromArray($config);
        $events = null;
        if ($c->kubernetes !== null && $c->on('kubernetes')) {
            $stack = HandlerStack::create(new MockHandler($queue));
            $historyLog = [];
            $this->history = &$historyLog; // Guzzle fills it by reference
            $stack->push(Middleware::history($historyLog));
            $events = new KubernetesEventsSource(new KubeApi(new Client(['handler' => $stack]), $c->kubernetes, $clock, static function (int $s): void {
            }), $c->kubernetes, $clock, new Normalizer(), 'orders', 86400, 86400 * 30);
        }

        return new Doctor($c, new FakeLogSource(['error' => 1]), new InMemoryStateStore($clock), null, new FakeNotifier(), $clock, events: $events);
    }

    private static function ok(): Response
    {
        return new Response(200, [], '{"items":[]}');
    }

    /** @return array<string, \Watcher\Operate\CheckResult> */
    private static function byName(\Watcher\Operate\CheckReport $r): array
    {
        $out = [];
        foreach ($r->results as $x) {
            $out[$x->name] = $x;
        }

        return $out;
    }

    public function testPassesWithGetOnlyReadsOfEventsAndPodsPerNamespace(): void
    {
        $r = self::byName($this->doctor(self::config(), array_fill(0, 4, self::ok()))->run());

        self::assertSame('pass', $r['events']->status);
        self::assertCount(4, $this->history, 'events and pods of two namespaces');
        foreach ($this->history as $h) {
            self::assertSame('GET', $h['request']->getMethod());
            self::assertSame('limit=1', $h['request']->getUri()->getQuery());
        }
    }

    public function testAForbiddenNamespaceIsNamedWithAFixedReason(): void
    {
        $r = self::byName($this->doctor(self::config(), [self::ok(), self::ok(), new Response(403, [], self::TOKEN)])->run());

        self::assertSame('fail', $r['events']->status);
        self::assertSame('namespace billing: unauthorised', $r['events']->reason);
        self::assertStringNotContainsString(self::TOKEN, $r['events']->reason);
    }

    public function testUnreachableClusterFails(): void
    {
        $r = self::byName($this->doctor(self::config(), array_fill(0, 8, new Response(503, [], '')))->run());

        self::assertSame('fail', $r['events']->status);
        self::assertSame('namespace shop: unavailable', $r['events']->reason);
    }

    public function testAnUnreadableTokenFileIsAFixedReasonCodeNotAPath(): void
    {
        $tf = sys_get_temp_dir() . '/kube-doctor-' . bin2hex(random_bytes(8));
        file_put_contents($tf, 'tok');
        chmod($tf, 0600);
        $cfg = self::config(['kubernetes' => ['api' => ['token' => null, 'token_file' => $tf]]]);
        unset($cfg['kubernetes']['api']['token']);
        $doctor = $this->doctor($cfg, array_fill(0, 4, self::ok()));
        file_put_contents($tf, "\n"); // emptied after the config was accepted
        $r = self::byName($doctor->run());
        unlink($tf);

        self::assertSame('fail', $r['events']->status);
        self::assertSame('namespace shop: credential unreadable', $r['events']->reason);
        self::assertStringNotContainsString($tf, $r['events']->reason);
    }

    public function testWarnsWhenAnExporterIsDeclaredAsWellAsNativeEvents(): void
    {
        $r = self::byName($this->doctor(self::config(['kubernetes' => ['exporter' => true]]), array_fill(0, 4, self::ok()))->run());

        self::assertSame('warn', $r['events']->status);
        self::assertStringContainsString('both', $r['events']->reason);
    }

    public function testWarnsWhenTheLogStreamLooksLikeAnEventExporter(): void
    {
        $cfg = self::config(['source' => ['stream_tags' => ['app' => 'kubernetes-event-exporter']]]);
        $r = self::byName($this->doctor($cfg, array_fill(0, 4, self::ok()))->run());

        self::assertSame('warn', $r['events']->status);
    }

    public function testAWarningIsNotAFailure(): void
    {
        $report = $this->doctor(self::config(['kubernetes' => ['exporter' => true]]), array_fill(0, 4, self::ok()))->run();

        self::assertSame(0, $report->failed());
        self::assertStringContainsString('WARN', $report->text());
    }

    public function testSkippedWhenTheFlagIsOff(): void
    {
        $r = self::byName($this->doctor(self::config(['kubernetes' => ['enabled' => false]]), [])->run());

        self::assertSame('skip', $r['events']->status);
        self::assertSame('disabled', $r['events']->reason);
        self::assertSame([], $this->history);
    }

    public function testNoEventsResultWithoutKubernetes(): void
    {
        $cfg = self::config();
        unset($cfg['kubernetes'], $cfg['events']);
        $r = self::byName($this->doctor($cfg, [])->run());

        self::assertArrayNotHasKey('events', $r);
    }
}
