<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Issues\SentryIssueSource;
use Watcher\Level;
use Watcher\Metrics\PrometheusSource;
use Watcher\Operate\RunOptions;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Watcher;

/** Spec 009 T049 / review M2: --dry-run and --estimate make the same read-only source calls as a run, say so, and write and post nothing. */
final class MetricsSentryDryRunTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $calls = [];

    private function http(): Client
    {
        $prom = (string) json_encode(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [1, '1.2']]]]]);
        $responses = [new Response(200, [], '[]'), new Response(200, [], $prom), new Response(200, [], $prom)]; // Sentry first (read with the logs), then metrics (current, baseline)
        $stack = HandlerStack::create(new MockHandler($responses));
        $log = [];
        $this->calls = &$log;
        $stack->push(Middleware::history($log));

        return new Client(['handler' => $stack]);
    }

    /** @return array{string, int, int, int} banner/output, prometheus calls, sentry calls, notifier posts */
    private function dryRun(RunOptions $options): array
    {
        $h = new Harness([
            'metrics' => ['source' => ['base_url' => 'https://prom.invalid'], 'queries' => ['latency' => ['query' => 'up', 'aggregate' => 'max']]],
            'sentry' => ['organization' => 'acme', 'project' => 'orders', 'auth_token' => 'tok-sentry-1234'],
        ]);
        $events = Harness::events(Level::Error, 'Alpha service exploded badly', 5, 'AlphaException');
        $source = new FakeLogSource(Harness::totalsOf($events), $events);
        $config = WatchConfig::fromArray($h->config());
        $make = function (?RunOptions $o, mixed $out = null) use ($h, $source, $config): Watcher {
            $http = $this->http();
            $clock = new FrozenClock($h->clock->now());

            return new Watcher($config, $source, $h->state, $h->analyzer, $h->notifier, $h->clock, null, $o, $out, metrics: new PrometheusSource($http, 'https://prom.invalid'), issues: new SentryIssueSource($http, 'https://sentry.io', 'acme', 'orders', 'tok-sentry-1234', clock: $clock));
        };
        $make(null)->run(); // the real baseline
        $h->nextWindow();
        $posted = count($h->notifier->sent);
        $stateBefore = serialize($h->state);
        $out = '';
        $make($options, static function (string $s) use (&$out): void {
            $out .= $s;
        })->run();

        $prom = count(array_filter($this->calls, static fn (array $c): bool => $c['request']->getUri()->getHost() === 'prom.invalid'));
        $sentry = count(array_filter($this->calls, static fn (array $c): bool => $c['request']->getUri()->getHost() === 'sentry.io'));
        self::assertSame($stateBefore, serialize($h->state), 'a dry run writes no state');
        self::assertSame($posted, count($h->notifier->sent), 'a dry run posts nothing');

        return [$out, $prom, $sentry, count($h->analyzer->requests) + count($h->analyzer->triageRequests)];
    }

    public function testEstimateReadsTheSourcesSaysSoAndCallsNoModel(): void
    {
        [$out, $prom, $sentry, $model] = $this->dryRun(RunOptions::dryRun(true));

        self::assertStringContainsString('no model calls made', $out);
        self::assertStringContainsString('external reads made (read-only, credentialed): metrics, sentry', $out);
        self::assertSame(2, $prom);
        self::assertSame(1, $sentry);
        self::assertSame(0, $model);
    }

    public function testDryRunReadsTheSourcesAndSaysSo(): void
    {
        [$out, $prom, $sentry] = $this->dryRun(RunOptions::dryRun());

        self::assertStringContainsString('external reads made (read-only, credentialed): metrics, sentry', $out);
        self::assertSame(2, $prom);
        self::assertSame(1, $sentry);
    }
}
