<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Observe\ObserveCounters;
use Watcher\Operate\JsonSummary;
use Watcher\Reduce\Normalizer;
use Watcher\Source\Kubernetes\KubeApi;
use Watcher\Source\KubernetesEventsSource;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\SpyLogger;
use Watcher\Watcher;

/** Principle I on the merged tree: a canary written into a Kubernetes event message reaches no sink, summary, log or notice. */
final class ObserveKubernetesPrivacyTest extends TestCase
{
    private const SECRET = 'CANARYSECRET-7Qk2xVd9Lm4Zp8Tn';
    private const EMAIL = 'canary.person@canary-corp.example';
    private const NAME = 'canaryvolumename';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/obsk8s-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                unlink($this->dir . '/' . $f);
            }
        }
        rmdir($this->dir);
    }

    /** @return list<Response> */
    private function responses(Harness $h): array
    {
        $dir = __DIR__ . '/../Fixtures/kubernetes/';
        $list = json_decode((string) file_get_contents($dir . 'events-v1.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($list['items'] as $i => $item) {
            $list['items'][$i]['note'] = 'Back-off restarting failed container in pod; secret ' . self::NAME . ' not found for ' . self::EMAIL . ' token=' . self::SECRET . ' https://u:' . self::SECRET . '@reg.example/v2/';
            $list['items'][$i]['series'] = ['count' => 5, 'lastObservedTime' => $h->clock->now()->modify('-30 minutes')->format('Y-m-d\TH:i:s\Z')];
        }
        $json = static fn (string $body): Response => new Response(200, ['Content-Type' => 'application/json'], $body);

        return [$json(json_encode($list, JSON_THROW_ON_ERROR)), $json((string) file_get_contents($dir . 'pods.json')), $json((string) file_get_contents($dir . 'pods-page2.json'))];
    }

    public function testACanaryInAPodEventMessageReachesNoSink(): void
    {
        $h = new Harness([
            'schedule' => ['run_at' => '06:00'],
            'kubernetes' => ['namespaces' => ['shop'], 'api' => ['server' => 'https://k8s.example', 'token' => 'BEARER-TEST-TOKEN-0123456789']],
            'events' => ['driver' => 'kubernetes'],
            'observe' => [
                'log' => ['enabled' => true, 'include_text' => true],
                'file' => ['enabled' => true, 'path' => $this->dir . '/events.jsonl', 'include_text' => true],
                'prometheus' => ['enabled' => true, 'dir' => $this->dir],
            ],
        ]);
        $h->hostLog = $hostLog = new SpyLogger();
        $config = WatchConfig::fromArray($h->config());
        self::assertNotNull($config->kubernetes);
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $queue = [];
        $h->clock->set(new DateTimeImmutable('2026-10-23T06:00:00Z'));
        for ($i = 0; $i < 4; ++$i) {
            $queue = [...$queue, ...$this->responses($h)];
        }
        $api = new KubeApi(new Client(['handler' => HandlerStack::create(new MockHandler($queue))]), $config->kubernetes, $h->clock, static function (int $s): void {
        });
        $events = new KubernetesEventsSource($api, $config->kubernetes, $h->clock, new Normalizer(), $config->stateKey, 86400, $config->state['retention']);
        $counters = new ObserveCounters($h->state, 'svc', 2592000);
        $sinks = Factory::observe($config, $h->clock, $hostLog, $counters, logStream: $stream);
        $watcher = new Watcher($config, new FakeLogSource([], []), $h->state, $h->analyzer, $h->notifier, $h->clock, $hostLog, null, null, events: $events, sinks: $sinks, counters: $counters);

        $watcher->run();
        $h->clock->set(new DateTimeImmutable('2026-10-24T06:00:00Z'));
        $result = $watcher->run();

        self::assertNotNull($result->summaryData);
        rewind($stream);
        $outputs = [
            'log sink' => (string) stream_get_contents($stream),
            'file sink' => (string) file_get_contents($this->dir . '/events.jsonl'),
            'prometheus' => (string) file_get_contents($this->dir . '/watcher_svc.prom'),
            'summary' => (new JsonSummary())->render($result->summaryData),
            'host log' => (string) json_encode($hostLog->records),
            'notifier' => (string) json_encode(array_map(static fn (array $s) => $s['message'], $h->notifier->sent), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'state' => (string) json_encode($h->state->all()),
        ];
        self::assertStringContainsString('pod event', $outputs['notifier'] . $outputs['file sink'], 'the pod events were read, or this proves nothing');
        foreach ($outputs as $where => $text) {
            foreach ([$text, (string) json_encode($text)] as $haystack) {
                foreach ([self::SECRET, self::EMAIL, self::NAME, 'canary-corp', 'BEARER-TEST-TOKEN'] as $needle) {
                    self::assertStringNotContainsString($needle, $haystack, "$needle leaked into $where");
                }
            }
        }
    }
}
