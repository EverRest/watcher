<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\Hygiene;
use Watcher\Hygiene\HygieneOptions;

/**
 * `watcher hygiene` wired from config (Hygiene::fromConfig), one test per source driver. HTTP is a Guzzle MockHandler,
 * so the run reads only the recorded responses; the history proves what left the process.
 */
final class HygieneFromConfigTest extends TestCase
{
    private const KUBE = __DIR__ . '/../Fixtures/kubernetes/';

    private const CANARY = 'CANARY-7Q2X';

    /** @var list<array<string, mixed>> */
    private array $history = [];

    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/hygiene-from-config-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    /** @param list<Response> $queue */
    private function http(array $queue): Client
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    private static function file(string $path): string
    {
        return (string) file_get_contents($path);
    }

    private function harness(array $patch): HygieneHarness
    {
        return new HygieneHarness(array_replace_recursive([
            'state' => ['driver' => 'file', 'file' => ['path' => $this->stateFile]],
        ], $patch));
    }

    /** @return array<string, mixed> the Kubernetes source, its API token a fake */
    private static function kubernetes(): array
    {
        return [
            'source' => ['driver' => 'kubernetes'],
            'kubernetes' => ['namespaces' => ['shop'], 'api' => ['server' => 'https://k8s.example', 'token' => 'BEARER-TEST-TOKEN-0123456789']],
            'events' => ['driver' => 'kubernetes'],
        ];
    }

    private function runHygiene(HygieneHarness $h, Client $http, bool $notify = false): \Watcher\Hygiene\HygieneResult
    {
        return Hygiene::fromConfig($h->config(), $h->clock, $http)->run(new HygieneOptions(notify: $notify));
    }

    public function testKubernetesDriverRunsWithItsStateBound(): void
    {
        $h = $this->harness(self::kubernetes());
        $events = new Response(200, ['Content-Type' => 'application/json'], self::file(self::KUBE . 'events-core.json'));
        $pods = new Response(200, ['Content-Type' => 'application/json'], self::file(self::KUBE . 'pods.json'));
        $pods2 = new Response(200, ['Content-Type' => 'application/json'], self::file(self::KUBE . 'pods-page2.json'));

        $r = $this->runHygiene($h, $this->http([$events, $pods, $pods2]));

        self::assertStringNotContainsString('internal error', (string) $r->failure, 'a kubernetes source must be bound to the state before it is read');
        self::assertSame(0, $r->exitCode());
    }

    public function testKubernetesDryRunWritesNoLedgerToTheRealState(): void
    {
        $h = $this->harness(self::kubernetes());
        $events = new Response(200, ['Content-Type' => 'application/json'], self::file(self::KUBE . 'events-core.json'));
        $pods = new Response(200, ['Content-Type' => 'application/json'], self::file(self::KUBE . 'pods.json'));
        $pods2 = new Response(200, ['Content-Type' => 'application/json'], self::file(self::KUBE . 'pods-page2.json'));

        $r = Hygiene::fromConfig($h->config(), $h->clock, $this->http([$events, $pods, $pods2]))->run(new HygieneOptions(dryRun: true));

        self::assertSame(0, $r->exitCode());
        // The file store creates an empty file on open; a written ledger would make it non-empty.
        self::assertTrue(!is_file($this->stateFile) || filesize($this->stateFile) === 0, 'a dry run shadows every write, the cluster ledger included');
    }

    public function testLokiDriverRunsFromConfig(): void
    {
        $h = $this->harness(['source' => ['driver' => 'loki']]);
        $empty = new Response(200, ['Content-Type' => 'application/json'], self::file(__DIR__ . '/../Fixtures/loki/empty.json'));

        $r = $this->runHygiene($h, $this->http(array_fill(0, 8, $empty)));

        self::assertStringNotContainsString('internal error', (string) $r->failure);
    }

    public function testDatadogDriverRunsFromConfig(): void
    {
        $h = $this->harness(['source' => ['driver' => 'datadog', 'datadog' => ['api_key' => 'DD-TEST-KEY', 'app_key' => 'DD-TEST-APP']]]);
        $empty = new Response(200, ['Content-Type' => 'application/json'], self::file(__DIR__ . '/../Fixtures/datadog/aggregate_empty.json'));

        $r = $this->runHygiene($h, $this->http(array_fill(0, 8, $empty)));

        self::assertStringNotContainsString('internal error', (string) $r->failure);
    }

    public function testRawKubernetesLineNeverLeavesTheProcess(): void
    {
        $h = $this->harness(self::kubernetes());
        $events = self::file(self::KUBE . 'events-core.json');
        // A canary planted in a pod event message: hygiene reads it locally and must send it nowhere.
        $planted = str_replace('Back-off restarting failed container', 'Back-off ' . self::CANARY . ' restarting failed container', $events);
        $ok = [new Response(200, ['Content-Type' => 'application/json'], $planted), new Response(200, ['Content-Type' => 'application/json'], self::file(self::KUBE . 'pods.json')), new Response(200, ['Content-Type' => 'application/json'], self::file(self::KUBE . 'pods-page2.json'))];

        // Notices on: the webhook POSTs are in the history too (the answers after the reads are plain 200s).
        $this->runHygiene($h, $this->http([...$ok, ...array_fill(0, 10, new Response(200))]), notify: true);

        self::assertNotEmpty($this->history, 'the run did read the cluster');
        foreach ($this->history as $t) {
            $request = $t['request'];
            self::assertStringNotContainsString(self::CANARY, (string) $request->getBody(), 'raw line sent to ' . $request->getUri());
            self::assertStringNotContainsString(self::CANARY, (string) $request->getUri());
        }
    }
}
