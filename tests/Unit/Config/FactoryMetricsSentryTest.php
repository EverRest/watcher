<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Issues\SentryIssueSource;
use Watcher\Metrics\PrometheusSource;
use Watcher\Schedule\Window;
use Watcher\Source\SourceUnavailable;
use Watcher\Tests\Fakes\FrozenClock;

final class FactoryMetricsSentryTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function config(bool $enabled = true): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki']],
            'analysis' => [
                'api_keys' => ['gemini' => 'k'],
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],

            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
            'metrics' => ['enabled' => $enabled, 'source' => ['base_url' => 'https://prom.invalid'], 'queries' => ['up' => ['query' => 'up', 'aggregate' => 'max']]],
            'sentry' => ['enabled' => $enabled, 'organization' => 'acme', 'project' => 'api', 'auth_token' => 'tok'],
        ];
    }

    public function testAbsentOrSwitchedOffYieldsNoSource(): void
    {
        $http = new Client();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-25T06:00:00Z'));
        $none = WatchConfig::fromArray(array_diff_key(self::config(), ['metrics' => 1, 'sentry' => 1]));
        $off = WatchConfig::fromArray(self::config(false));
        foreach ([$none, $off] as $c) {
            self::assertNull(Factory::metricSource($c, $http));
            self::assertNull(Factory::issueSource($c, $http, $clock));
        }
    }

    public function testBuildsBothSourcesAndTheStopHookReachesThem(): void
    {
        $c = WatchConfig::fromArray(self::config());
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-25T06:00:00Z'));
        $calls = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '[]')]));
        $stack->push(Middleware::history($calls));
        $http = new Client(['handler' => $stack]);
        $stop = static fn (): string => 'deadline';

        $prom = Factory::metricSource($c, $http, $stop);
        $sentry = Factory::issueSource($c, $http, $clock, $stop);
        self::assertInstanceOf(PrometheusSource::class, $prom);
        self::assertInstanceOf(SentryIssueSource::class, $sentry);

        // a stop reason ends the call before any request leaves: the token is `timeout`, nothing was sent
        try {
            $sentry->issues(new Window($clock->now()->modify('-1 day'), $clock->now()), 5);
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('timeout', $e->getMessage());
        }
        self::assertSame([], $calls);
        self::assertSame(60, Factory::SOURCE_BUDGET_SECONDS);
    }
}
