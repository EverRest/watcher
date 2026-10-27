<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Observe\Event;
use Watcher\Observe\ObserveCounters;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class ObserveFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/obsfactory-' . bin2hex(random_bytes(6));
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

    /**
     * @param array<string, mixed> $observe
     */
    private function config(array $observe, string $driver = 'slack'): WatchConfig
    {
        return WatchConfig::fromArray([
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example.com']],
            'analysis' => ['api_key' => 'sk-secret-key', 'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'], ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => $driver === 'none' ? ['driver' => 'none'] : ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
            'observe' => $observe,
        ]);
    }

    private function clock(): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable('2026-10-24T12:00:00+00:00'));
    }

    private function counters(): ObserveCounters
    {
        return new ObserveCounters(new InMemoryStateStore($this->clock()), 'svc', 86400);
    }

    private function event(): Event
    {
        return new Event('run', ['schema' => 'watcher.event.v2', 'event_type' => 'run', 'event_id' => 'a-1', 'service' => 'svc'], []);
    }

    public function testNoSinkEnabledBuildsNothing(): void
    {
        self::assertNull(Factory::observe($this->config([]), $this->clock(), null, $this->counters()));
    }

    public function testEnabledSinksAreBuiltAndWriteTheirOutput(): void
    {
        $c = $this->config(['file' => ['enabled' => true, 'path' => $this->dir . '/e.jsonl'], 'prometheus' => ['enabled' => true, 'dir' => $this->dir]]);

        $d = Factory::observe($c, $this->clock(), null, $this->counters());

        self::assertNotNull($d);
        self::assertSame([], $d->dispatch([$this->event()]));
        self::assertFileExists($this->dir . '/e.jsonl');
        self::assertFileExists($this->dir . '/watcher_svc.prom');
        self::assertSame(0600, fileperms($this->dir . '/e.jsonl') & 0777);
    }

    public function testTheHttpSinkUsesARedirectFreeClientAndTheTimeBoundedPath(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200)]));
        $stack->push(Middleware::history($history));
        $c = $this->config(['http' => ['enabled' => true, 'url' => 'https://ingest.example.com/in', 'auth_token' => 'tok-observe-1234']]);

        $d = Factory::observe($c, $this->clock(), null, $this->counters(), new Client(['handler' => $stack]));

        self::assertNotNull($d);
        self::assertSame([], $d->dispatch([$this->event()], 9));
        $sent = (array) $history;
        self::assertCount(1, $sent);
        self::assertNotNull($sent[0]);
        self::assertFalse($sent[0]['options']['allow_redirects'] ?? true);
        self::assertSame('Bearer tok-observe-1234', $sent[0]['request']->getHeaderLine('Authorization'));
        self::assertSame(['allow_redirects' => false, 'connect_timeout' => 5], Factory::observeHttpOptions(5));
    }

    public function testTheBuiltHttpSinkNeverSleepsPastTheRunDeadline(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(429, ['Retry-After' => '15']), new Response(200)]));
        $c = $this->config(['http' => ['enabled' => true, 'url' => 'https://ingest.example.com/in']]);
        $d = Factory::observe($c, $this->clock(), null, $this->counters(), new Client(['handler' => $stack]));
        self::assertNotNull($d);

        $t = microtime(true);
        $failures = $d->dispatch([$this->event()], 2);

        self::assertSame(['http' => 1], $failures);
        self::assertLessThan(1.5, microtime(true) - $t, 'a 15 s Retry-After does not fit in 2 s and is not slept');
    }

    public function testDriverNoneYieldsANotifierRouteThatNeverTouchesTheNetwork(): void
    {
        $c = $this->config(['log' => ['enabled' => true]], 'none');
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([]))]); // any request would throw

        Factory::notifier($http, 900)->send(new \Watcher\Notify\Message('x'), $c->destination('digest'));

        $this->addToAssertionCount(1);
    }
}
