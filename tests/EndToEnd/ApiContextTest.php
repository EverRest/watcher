<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\CompactPayload;
use Watcher\Analyze\TriageRequest;
use Watcher\ApiContext\ApiContextReader;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Operate\RunOptions;
use Watcher\Privacy\Redactor;
use Watcher\Source\LogEvent;
use Watcher\State\StateStore;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\RecordingStateStore;
use Watcher\Tests\Fakes\SpyLogger;
use Watcher\Watcher;

/** Spec 010 end to end: the digest line, the `ep` field, the cap, the cache, the failure note, the privacy of an OpenAPI text. */
final class ApiContextTest extends TestCase
{
    public const EMAIL = 'canary.person@canary-corp.example';
    public const TOKEN = 'ghp_CanaryTokenAbCdEfGhIjKlMnOpQrStUvWx';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/apictx-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @param array<string, array<string, mixed>> $paths */
    private function spec(array $paths): string
    {
        $file = $this->dir . '/openapi.json';
        file_put_contents($file, json_encode(['openapi' => '3.0.0', 'paths' => $paths], JSON_THROW_ON_ERROR));

        return $file;
    }

    /** @return list<LogEvent> */
    private static function events(string ...$messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            array_push($out, ...Harness::events(Level::Error, $m, 4, 'ApiException'));
        }

        return $out;
    }

    /**
     * Two quiet baseline windows' worth of history, then the run under test.
     *
     * @param array<string, mixed>|null $api
     * @param list<LogEvent>|\Closure(int): list<LogEvent> $events the window's events; a closure gets the window number (0 = baseline)
     */
    private function go(Harness $h, ?array $api, array|\Closure $events, ?RunOptions $options = null, int $windows = 1, ?\Closure $before = null): \Watcher\RunResult
    {
        $config = WatchConfig::fromArray(array_replace_recursive($h->config(), $api === null ? [] : ['api_context' => $api]));
        $window = 0;
        $make = function (?RunOptions $o) use (&$window, $events, $config, $h): Watcher {
            $ev = $events instanceof \Closure ? $events($window) : $events;

            return new Watcher($config, new FakeLogSource(Harness::totalsOf($ev), $ev), $h->state, $h->analyzer, $h->notifier, $h->clock, $h->hostLog, $o, static function (string $s): void {
            }, apiContext: static fn (StateStore $s, Redactor $r): ?ApiContextReader => Factory::apiContextReader(config: $config, http: Factory::http(), state: $s, redactor: $r, clock: $h->clock));
        };
        $make(null)->run(); // baseline window
        $result = null;
        for ($i = 0; $i < $windows; ++$i) {
            ++$window;
            $h->nextWindow();
            if ($before !== null) {
                $before($i);
            }
            $result = $make($options)->run();
        }
        self::assertNotNull($result);

        return $result;
    }

    private function harness(): Harness
    {
        return new Harness(['schedule' => ['run_at' => '06:00']]);
    }

    private function digest(Harness $h): string
    {
        return implode("\n", $h->sentTexts());
    }

    public function testAMatchedRouteGivesADigestLineAndAnEpFieldInAnalysisNotTriage(): void
    {
        $h = $this->harness();
        $file = $this->spec(['/orders/{id}' => ['get' => ['summary' => 'Get an order', 'tags' => ['orders'], 'deprecated' => true]], '/health' => ['get' => ['summary' => 'Liveness']]]);

        $this->go($h, ['source' => $file], self::events('GET /orders/8812 failed: timeout', 'something else broke'));

        self::assertStringContainsString('📖 GET /orders/{id} — Get an order [orders] (deprecated)', $this->digest($h));
        self::assertStringNotContainsString('Liveness', $this->digest($h), 'an operation no group names is not shown');
        $eps = array_merge(...array_map(static fn ($r): array => $r->endpoints, $h->analyzer->requests));
        self::assertSame(['GET /orders/{id} — Get an order'], array_values($eps));
        self::assertNotEmpty($h->analyzer->triageRequests);
        foreach ($h->analyzer->triageRequests as $t) {
            self::assertInstanceOf(TriageRequest::class, $t);
            self::assertStringNotContainsString('"ep"', CompactPayload::encode($t->groups));
        }
        self::assertStringContainsString('"ep":"GET /orders/{id} — Get an order"', CompactPayload::encode($h->analyzer->requests[0]->groups, [], $h->analyzer->requests[0]->endpoints));
    }

    public function testAnUnmatchedRouteAttachesNothingAndSaysNothing(): void
    {
        $h = $this->harness();

        $this->go($h, ['source' => $this->spec(['/orders/{id}' => ['get' => ['summary' => 'Get an order']]])], self::events('GET /nothing/8812 failed'));

        self::assertStringNotContainsString('📖', $this->digest($h));
        self::assertStringNotContainsString('API context', $this->digest($h));
    }

    public function testEightRoutesWithTheDefaultCapOfFiveGiveFiveLinesAndAFootnote(): void
    {
        $h = $this->harness();
        $names = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel'];
        $words = ['rejected the cardholder name', 'lost connection to cache cluster', 'ran out of disk quota', 'refused TLS renegotiation', 'hit deadlock on ledger rows', 'could not parse the invoice template', 'exceeded the retry ceiling', 'saw clock skew beyond tolerance'];
        $paths = [];
        $messages = [];
        for ($i = 1; $i <= 8; ++$i) {
            $paths['/' . $names[$i - 1]] = ['get' => ['summary' => 'Route ' . $names[$i - 1]]];
            $messages[] = 'GET /' . $names[$i - 1] . ' ' . $words[$i - 1];
        }

        $this->go($h, ['source' => $this->spec($paths), 'max_endpoints' => 5], self::events(...$messages));

        self::assertSame(5, substr_count($this->digest($h), '📖'));
        self::assertStringContainsString('3 endpoint line(s) skipped (api_context.max_endpoints)', $this->digest($h));
    }

    public function testWithoutTheSectionTheDigestAndPayloadsAreTheSameAndNothingIsStored(): void
    {
        $file = $this->spec(['/orders/{id}' => ['get' => ['summary' => 'Get an order']]]);
        $events = self::events('GET /orders/8812 failed: timeout');
        $off = $this->harness();
        $off->state = $spy = new RecordingStateStore($off->state);
        $this->go($off, null, $events);
        $disabled = $this->harness();
        $this->go($disabled, ['enabled' => false, 'source' => $file], $events);

        self::assertSame([], array_values(array_filter($spy->putKeys(), static fn (string $k): bool => str_contains($k, 'api'))));
        self::assertStringNotContainsString('📖', $this->digest($off));
        self::assertSame($this->digest($off), $this->digest($disabled));
        self::assertSame([], $off->analyzer->requests[0]->endpoints);
        self::assertSame([], $disabled->analyzer->requests[0]->endpoints);
        self::assertSame(
            CompactPayload::encode($off->analyzer->requests[0]->groups),
            CompactPayload::encode($disabled->analyzer->requests[0]->groups, [], $disabled->analyzer->requests[0]->endpoints),
        );
    }

    public function testNoRouteInAnyTemplateMeansTheSourceIsNeverOpened(): void
    {
        $h = $this->harness();
        $h->state = $spy = new RecordingStateStore($h->state);

        $this->go($h, ['source' => $this->dir . '/does-not-exist.json'], self::events('timeout after 3 / 5 attempts'));

        self::assertSame([], array_filter($spy->calls, static fn (array $c): bool => str_contains($c[1], 'api')));
        self::assertStringNotContainsString('API context', $this->digest($h));
    }

    /** @return array<string, array{string}> */
    public static function failures(): array
    {
        return ['missing file' => ['unreadable'], 'not json' => ['unsupported_format'], 'too large' => ['too_large']];
    }

    /** @dataProvider failures */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testAnUnreadableSourceIsOneNoteAndTheFindingsAreUnchanged(string $token): void
    {
        $events = self::events('GET /orders/8812 failed: timeout');
        $file = $this->dir . '/openapi.json';
        $baseline = $this->harness();
        $this->go($baseline, null, $events);
        $h = $this->harness();
        $api = ['source' => $file];
        match ($token) {
            'unsupported_format' => file_put_contents($file, "openapi: 3.0.0\n"),
            'too_large' => file_put_contents($file, '{"openapi":"3.0.0","paths":{},"x":"' . str_repeat('a', 70000) . '"}'),
            default => null,
        };
        if ($token === 'too_large') {
            $api['max_bytes'] = 65536;
        }
        $h->hostLog = $log = new SpyLogger();

        $this->go($h, $api, $events);

        self::assertSame(1, substr_count($this->digest($h), 'API context unavailable (' . $token . ')'));
        self::assertSame(
            $this->withoutNote($this->digest($baseline)),
            $this->withoutNote($this->digest($h)),
        );
        self::assertStringNotContainsString($this->dir, (string) json_encode($log->records), 'no path in the log');
    }

    private function withoutNote(string $s): string
    {
        return (string) preg_replace('~^.*API context unavailable.*\n?~m', '', $s);
    }

    public function testASecondRunInsideTheTtlMakesNoReadOfTheSource(): void
    {
        $h = $this->harness();
        $h->state = $spy = new RecordingStateStore($h->state);
        $file = $this->spec(['/orders/{id}' => ['get' => ['summary' => 'Get an order']]]);
        $events = static fn (int $w): array => $w === 0 ? self::events('GET /orders/8812 failed: timeout') : array_merge(...array_fill(0, 10 ** $w, self::events('GET /orders/8812 failed: timeout'))); // growing: every window is analysed again

        // window 2 reads and caches; before window 3 the file is gone: a cache hit is the only way it is not reported as unavailable
        $this->go($h, ['source' => $file, 'ttl' => 'PT72H'], $events, windows: 2, before: function (int $i) use ($file): void {
            if ($i === 1) {
                unlink($file);
            }
        });

        $api = static fn (string $kind): int => count(array_filter($spy->calls, static fn (array $c): bool => $c[0] === $kind && str_contains($c[1], 'api')));
        self::assertSame(1, $api('put'), 'one cache write: the second window did not read the source');
        self::assertGreaterThanOrEqual(2, $api('get'), 'both reporting windows consulted the cache');
        self::assertStringContainsString('📖 GET /orders/{id} — Get an order', $this->digest($h));
        self::assertStringNotContainsString('API context unavailable', $this->digest($h));
    }

    public function testADryRunReadsTheSourceAndWritesNoCacheEntry(): void
    {
        $h = $this->harness();
        $file = $this->spec(['/orders/{id}' => ['get' => ['summary' => 'Get an order']]]);
        $h->state = $spy = new RecordingStateStore($h->state);
        $out = '';

        $this->go($h, ['source' => $file], self::events('GET /orders/8812 failed: timeout'), RunOptions::dryRun(), before: null);

        self::assertSame([], array_filter($spy->putKeys(), static fn (string $k): bool => str_contains($k, ':api:') || str_starts_with($k, 'watcher:api') || str_contains($k, 'api:svc')));
        unset($out);
        self::assertSame(1, count(array_filter($spy->calls, static fn (array $c): bool => $c[0] === 'get' && str_contains($c[1], 'api'))), 'the cache was consulted');
    }

    public function testNoOpenApiTextOtherThanTheMaskedSixFieldsReachesTheModelTheDigestOrASink(): void
    {
        $h = $this->harness();
        $h->hostLog = $log = new SpyLogger();
        $file = $this->spec([
            '/orders/{id}' => ['get' => [
                'summary' => 'Orders for ' . self::EMAIL . ' key ' . self::TOKEN,
                'description' => 'DESCRIPTION-CANARY ' . self::EMAIL,
                'tags' => ['tag-' . self::EMAIL],
                'parameters' => [['name' => 'id', 'in' => 'path', 'example' => 'PARAM-EXAMPLE-CANARY ' . self::TOKEN]],
                'responses' => ['200' => ['content' => ['application/json' => ['example' => ['email' => self::EMAIL, 'token' => self::TOKEN]]]]],
                'x-internal' => self::TOKEN,
            ]],
            '/refunds' => ['post' => ['operationId' => 'refund-' . self::EMAIL . '-' . self::TOKEN, 'description' => self::EMAIL]],
        ]);
        $events = self::events('GET /orders/8812 failed: timeout', 'POST /refunds failed: boom');
        $config = array_replace_recursive($h->config(), ['observe' => ['file' => ['enabled' => true, 'path' => $this->dir . '/events.jsonl', 'include_text' => true], 'log' => ['enabled' => true, 'include_text' => true]]]);
        $h = new Harness(['observe' => $config['observe'], 'schedule' => ['run_at' => '06:00']]);
        $h->hostLog = $log;
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $wc = WatchConfig::fromArray(array_replace_recursive($h->config(), ['api_context' => ['source' => $file]]));
        $sinks = Factory::observe($wc, $h->clock, $log, new \Watcher\Observe\ObserveCounters($h->state, 'svc', 2592000), logStream: $stream);
        $make = fn () => new Watcher($wc, new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $h->analyzer, $h->notifier, $h->clock, $log, null, null, sinks: $sinks, counters: null, apiContext: static fn (StateStore $s, Redactor $r): ?ApiContextReader => Factory::apiContextReader(config: $wc, http: Factory::http(), state: $s, redactor: $r, clock: $h->clock));
        $make()->run();
        $h->nextWindow();
        $result = $make()->run();

        rewind($stream);
        $requests = (string) json_encode(array_map(static fn ($r): string => CompactPayload::encode($r->groups, $r->dependencyContext, $r->endpoints), $h->analyzer->requests), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $outputs = [
            'model payload' => $requests,
            'digest' => $this->digest($h),
            'log sink' => (string) stream_get_contents($stream),
            'file sink' => (string) @file_get_contents($this->dir . '/events.jsonl'),
            'summary' => $result->summaryData === null ? '' : (new \Watcher\Operate\JsonSummary())->render($result->summaryData),
            'host log' => (string) json_encode($log->records),
            'state' => (string) json_encode($h->state instanceof \Watcher\Tests\Fakes\InMemoryStateStore ? $h->state->all() : []),
        ];
        self::assertStringContainsString('ep\\":', $outputs['model payload'], 'the test has teeth: the endpoint context was sent');
        self::assertStringContainsString('📖', $outputs['digest']);
        foreach ($outputs as $where => $text) {
            foreach ([$text, (string) json_encode($text)] as $haystack) {
                foreach ([self::EMAIL, 'canary-corp', self::TOKEN, 'CanaryToken', 'DESCRIPTION-CANARY', 'PARAM-EXAMPLE-CANARY'] as $needle) {
                    self::assertStringNotContainsString($needle, $haystack, "$needle leaked into $where");
                }
            }
        }
        $cached = array_filter($h->state instanceof \Watcher\Tests\Fakes\InMemoryStateStore ? $h->state->all() : [], static fn (string $k): bool => str_contains($k, 'api'), ARRAY_FILTER_USE_KEY);
        self::assertNotSame([], $cached, 'the reduced spec was cached');
        self::assertStringContainsString('Orders for', (string) json_encode($cached), 'the reduced, masked summary is what is stored');
    }
}
