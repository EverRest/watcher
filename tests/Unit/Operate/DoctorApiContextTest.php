<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Operate\CheckResult;
use Watcher\Operate\Doctor;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\RecordingStateStore;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class DoctorApiContextTest extends TestCase
{
    private FrozenClock $clock;
    private RecordingStateStore $store;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-25T09:00:00Z'));
        $this->store = new RecordingStateStore(new InMemoryStateStore($this->clock));
    }

    /** @param array<string, mixed>|null $api */
    private function report(?array $api, MockHandler $mock): ?CheckResult
    {
        $c = WatchConfig::fromArray(array_filter([
            'service' => 'orders', 'enabled' => true, 'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.test'], 'stream_tags' => ['app' => 'orders']],
            'analysis' => ['api_key' => 'sk', 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c'], ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/x']],
            'notify' => ['digest' => ['webhook' => 'https://hooks.slack.test/services/T0/B0/SECRETHOOK']],
            'api_context' => $api,
        ], static fn (mixed $v): bool => $v !== null));
        $reader = Factory::apiContextReader(config: $c, http: new Client(['handler' => HandlerStack::create($mock)]), state: $this->store, redactor: new Redactor(new Normalizer()), clock: $this->clock);
        $notifier = new FakeNotifier();
        $report = (new Doctor($c, new FakeLogSource(['error' => 1]), $this->store, null, $notifier, $this->clock, apiContext: $reader))->run();
        self::assertSame([], $notifier->sent, 'a plain check posts nothing');
        foreach ($report->results as $r) {
            if ($r->name === 'api_context') {
                return $r;
            }
        }

        return null;
    }

    public function testAbsentOrDisabledAddsNoCheck(): void
    {
        self::assertNull($this->report(null, new MockHandler()));
        self::assertNull($this->report(['enabled' => false, 'source' => 'https://docs.example.com/o.json'], new MockHandler()));
    }

    public function testReadableDocumentPassesWithCountsAndBypassesTheCache(): void
    {
        $mock = new MockHandler([new Response(200, [], '{"openapi":"3.0.0","paths":{"/orders/{id}":{"get":{"summary":"Get"}},"/a/b/c/d":{"get":{"summary":"deep"}}}}')]);
        $this->store->calls = [];

        $r = $this->report(['source' => 'https://docs.example.com/o.json'], $mock);

        self::assertNotNull($r);
        self::assertSame('pass', $r->status);
        self::assertSame('1 operations kept, 1 not kept', $r->reason);
        self::assertSame([], array_filter($this->store->calls, static fn (array $c): bool => str_contains($c[1], 'api')), 'no cache get or put');
    }

    /** @return array<string, array{Response, string}> */
    public static function failures(): array
    {
        return [
            'unauthorized' => [new Response(401), 'unauthorized'],
            'unreachable' => [new Response(503), 'unreachable'],
            'not json' => [new Response(200, [], "openapi: 3.0.0\n"), 'unsupported_format'],
            'too large' => [new Response(200, ['Content-Length' => '9999999'], '{}'), 'too_large'],
            'wrong version' => [new Response(200, [], '{"openapi":"4.0.0","paths":{}}'), 'unsupported_version'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testEachFailureTokenFailsWithThatReasonOnly(Response $response, string $token): void
    {
        $r = $this->report(['source' => 'https://docs.example.com/secret-path/o.json?k=SECRETQ', 'auth_token' => 'TOKEN-CANARY'], new MockHandler([$response]));

        self::assertNotNull($r);
        self::assertSame('fail', $r->status);
        self::assertSame($token, $r->reason);
    }

    public function testAnUnsafeSourceIsReportedAsItsOwnToken(): void
    {
        $c = WatchConfig::fromArray([
            'service' => 'orders', 'enabled' => true, 'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.test'], 'stream_tags' => ['app' => 'orders']],
            'analysis' => ['api_key' => 'sk', 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c'], ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/x']],
            'notify' => ['digest' => ['webhook' => 'https://hooks.slack.test/services/T0/B0/SECRETHOOK']],
        ]);
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $reader = new \Watcher\ApiContext\ApiContextReader(
            new \Watcher\ApiContext\OpenApiSource(new Client(['handler' => HandlerStack::create($mock)]), 'https://metadata.google.internal/openapi.json'),
            new \Watcher\ApiContext\OpenApiReducer(new Redactor(new Normalizer())),
            new \Watcher\ApiContext\ApiSpecCache($this->store, $this->clock, 3600),
            'orders',
        );

        $report = (new Doctor($c, new FakeLogSource(['error' => 1]), $this->store, null, new FakeNotifier(), $this->clock, apiContext: $reader))->run();
        $by = array_values(array_filter($report->results, static fn (CheckResult $r): bool => $r->name === 'api_context'));

        self::assertSame('unsafe_source', $by[0]->reason);
        self::assertSame(1, $mock->count(), 'no request was made');
    }
}
