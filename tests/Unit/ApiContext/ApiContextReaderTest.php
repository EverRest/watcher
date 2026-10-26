<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\ApiContext;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\ApiContext\ApiContextReader;
use Watcher\ApiContext\ApiSpecCache;
use Watcher\ApiContext\OpenApiReducer;
use Watcher\ApiContext\OpenApiSource;
use Watcher\Operate\DryRunStateStore;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Tests\Fakes\FailingStateStore;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\RecordingStateStore;

final class ApiContextReaderTest extends TestCase
{
    private const URL = 'https://docs.example/openapi.json';
    private const DOC = '{"openapi":"3.0.0","paths":{"/orders/{id}":{"get":{"summary":"Get an order"}}}}';

    private FrozenClock $clock;
    private InMemoryStateStore $inner;
    private RecordingStateStore $store;
    private MockHandler $mock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-24T06:00:00Z'));
        $this->inner = new InMemoryStateStore($this->clock);
        $this->store = new RecordingStateStore($this->inner);
        $this->mock = new MockHandler();
    }

    private function reader(?\Watcher\State\StateStore $store = null, int $ttl = 3600, string $service = 'orders'): ApiContextReader
    {
        $http = new Client(['handler' => HandlerStack::create($this->mock)]);

        return new ApiContextReader(
            new OpenApiSource($http, self::URL),
            new OpenApiReducer(new Redactor(new Normalizer())),
            new ApiSpecCache($store ?? $this->store, $this->clock, $ttl),
            $service,
        );
    }

    public function testMissReadsOnceAndCaches(): void
    {
        $this->mock->append(new Response(200, [], self::DOC));
        $r = $this->reader()->spec();

        self::assertSame('miss', $r->cache);
        self::assertNull($r->failure);
        self::assertSame('/orders/{id}', $r->spec?->ops[0]->template);
        self::assertSame(['api:orders:' . substr(hash('sha256', self::URL), 0, 12) . '-' . (new OpenApiReducer(new Redactor(new Normalizer())))->fingerprint()], $this->store->putKeys());
        self::assertSame(3600, array_values($this->inner->ttls())[0]);
    }

    public function testSecondCallInsideTheTtlTouchesNoSource(): void
    {
        $this->mock->append(new Response(200, [], self::DOC));
        $reader = $this->reader();
        $reader->spec();
        $r = $reader->spec();

        self::assertSame('hit', $r->cache);
        self::assertSame(0, $this->mock->count(), 'the queue is empty: one request in total, the second call made none');
    }

    public function testOneReadAfterTheTtl(): void
    {
        $this->mock->append(new Response(200, [], self::DOC), new Response(200, [], self::DOC));
        $reader = $this->reader();
        $reader->spec();
        $this->clock->advance('+3601 seconds');
        self::assertSame('miss', $reader->spec()->cache);
        self::assertSame(0, $this->mock->count());
    }

    public function testFailureIsATokenNeverCachedNeverThrown(): void
    {
        foreach ([[new Response(401), 'unauthorized'], [new Response(200, [], 'openapi: 3.0.0'), 'unsupported_format'], [new Response(200, [], '{"openapi":"4.0.0","paths":{}}'), 'unsupported_version']] as [$resp, $token]) {
            $this->mock->append($resp);
            $r = $this->reader()->spec();
            self::assertNull($r->spec);
            self::assertSame($token, $r->failure);
            self::assertSame('failed', $r->cache);
        }
        self::assertSame([], $this->store->putKeys());
    }

    public function testDifferentServicesDoNotShareAnEntry(): void
    {
        $this->mock->append(new Response(200, [], self::DOC), new Response(200, [], self::DOC));
        self::assertSame('miss', $this->reader(null, 3600, 'a')->spec()->cache);
        self::assertSame('miss', $this->reader(null, 3600, 'b')->spec()->cache);
    }

    public function testBrokenStateStoreDegradesToAnUncachedRead(): void
    {
        $this->mock->append(new Response(200, [], self::DOC));
        $broken = new FailingStateStore($this->inner);
        $broken->failOn('get');
        $broken->failOn('put');
        $r = $this->reader($broken)->spec();
        self::assertNotNull($r->spec);
        self::assertNull($r->failure);
    }

    public function testReadFreshBypassesTheCacheBothWays(): void
    {
        $this->mock->append(new Response(200, [], self::DOC), new Response(200, [], self::DOC));
        $reader = $this->reader();
        $reader->spec();
        $calls = count($this->store->calls);

        $r = $reader->readFresh();
        self::assertNotNull($r->spec);
        self::assertSame('none', $r->cache);
        self::assertSame(0, $this->mock->count(), 'it read the source although the entry was fresh');
        self::assertCount($calls, $this->store->calls, 'no get, no put');
    }

    public function testDryRunReadsThroughAndWritesNothingToTheRealStore(): void
    {
        $this->mock->append(new Response(200, [], self::DOC));
        $r = $this->reader(new DryRunStateStore($this->store))->spec();
        self::assertNotNull($r->spec);
        self::assertSame([], $this->store->putKeys());
        self::assertSame([], $this->inner->all());
    }

    public function testChangedReducerSettingsDoNotReadAnEntryMaskedUnderTheOldOnes(): void
    {
        $http = new Client(['handler' => HandlerStack::create($this->mock)]);
        $make = fn (int $cap): ApiContextReader => new ApiContextReader(
            new OpenApiSource($http, self::URL),
            new OpenApiReducer(new Redactor(new Normalizer()), $cap),
            new ApiSpecCache($this->store, $this->clock, 3600),
            'orders',
        );
        $this->mock->append(new Response(200, [], self::DOC), new Response(200, [], self::DOC));

        self::assertSame('miss', $make(80)->spec()->cache);
        self::assertSame('hit', $make(80)->spec()->cache);
        self::assertSame('miss', $make(60)->spec()->cache, 'a new summary_cap is a new key');
        self::assertCount(2, $this->store->putKeys());
    }
}
