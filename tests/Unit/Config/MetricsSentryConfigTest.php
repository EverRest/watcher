<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

/** Spec 009: the `metrics` and `sentry` sections (contracts/config.md): key-path errors, the shared URL classifier, the secrets. */
final class MetricsSentryConfigTest extends TestCase
{
    private const PROM_TOKEN = 'CANARY-prom-token-77aa';
    private const SENTRY_TOKEN = 'CANARY-sentry-token-77aa-with-a-longer-tail';

    /** @return array<string, mixed> */
    private static function metrics(): array
    {
        return [
            'source' => ['type' => 'prometheus', 'base_url' => 'https://prom.example.com', 'auth' => ['type' => 'bearer', 'token' => self::PROM_TOKEN]],
            'queries' => ['error_rate' => ['query' => 'sum(rate(x{service="{service}"}[{window}]))', 'aggregate' => 'sum', 'threshold' => ['relative' => 0.5, 'floor' => 0.01]]],
        ];
    }

    /** @return array<string, mixed> */
    private static function sentry(): array
    {
        return ['organization' => 'acme', 'project' => 'orders-api', 'auth_token' => self::SENTRY_TOKEN];
    }

    /**
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    private static function config(array $patch = []): array
    {
        return array_replace([
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki']],
            'analysis' => [
                'api_key' => 'sk-secret-key',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/SECRET']],
        ], $patch);
    }

    public function testAbsentSectionsAreNull(): void
    {
        $c = WatchConfig::fromArray(self::config());
        self::assertNull($c->metrics);
        self::assertNull($c->sentry);
    }

    public function testBothSectionsParseWithDefaults(): void
    {
        $c = WatchConfig::fromArray(self::config(['metrics' => self::metrics(), 'sentry' => self::sentry()]));
        self::assertNotNull($c->metrics);
        self::assertNotNull($c->sentry);
        self::assertTrue($c->metrics->enabled);
        self::assertSame(3, $c->metrics->maxAnomalies);
        self::assertSame(500, $c->metrics->maxPoints);
        self::assertCount(1, $c->metrics->definitions);
        self::assertSame('https://sentry.io', $c->sentry->baseUrl);
        self::assertSame(50, $c->sentry->maxIssues);
        self::assertFalse($c->sentry->allowPrivateNetwork);
    }

    public function testASwitchedOffSectionIsStillValidated(): void
    {
        $this->expectException(InvalidConfig::class);
        WatchConfig::fromArray(self::config(['sentry' => ['enabled' => false] + ['organization' => '..'] + self::sentry()]));
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function bad(): iterable
    {
        $m = self::metrics();
        $s = self::sentry();
        yield 'metrics unknown key' => ['metrics', $m + ['nope' => 1], 'metrics.nope'];
        yield 'metrics http without allow_insecure' => ['metrics', ['source' => ['base_url' => 'http://prom.example.com'] + $m['source']] + $m, 'metrics.source.base_url'];
        yield 'metrics RFC1918 without allow_private_network' => ['metrics', ['source' => ['base_url' => 'https://10.1.2.3:9090'] + $m['source']] + $m, 'metrics.source.base_url'];
        yield 'metrics cluster-local without allow_private_network' => ['metrics', ['source' => ['base_url' => 'https://prometheus.monitoring.svc'] + $m['source']] + $m, 'metrics.source.base_url'];
        yield 'metrics metadata even with the flag' => ['metrics', ['source' => ['base_url' => 'https://169.254.169.254', 'allow_private_network' => true] + $m['source']] + $m, 'metrics.source.base_url'];
        yield 'metrics userinfo' => ['metrics', ['source' => ['base_url' => 'https://u:p@prom.example.com'] + $m['source']] + $m, 'metrics.source.base_url'];
        yield 'grafana without uid' => ['metrics', ['source' => ['type' => 'grafana'] + $m['source']] + $m, 'metrics.source.datasource_uid'];
        yield 'grafana dotdot uid' => ['metrics', ['source' => ['type' => 'grafana', 'datasource_uid' => '..'] + $m['source']] + $m, 'metrics.source.datasource_uid'];
        yield 'metrics bad auth' => ['metrics', ['source' => ['auth' => ['type' => 'basic']] + $m['source']] + $m, 'metrics.source.auth'];
        yield 'metrics no queries' => ['metrics', ['queries' => []] + $m, 'metrics.queries'];
        yield 'metrics too many queries' => ['metrics', ['queries' => array_fill_keys(['a', 'b', 'c', 'd', 'e', 'f', 'g'], $m['queries']['error_rate'])] + $m, 'metrics.queries'];
        yield 'metrics unknown placeholder' => ['metrics', ['queries' => ['x' => ['query' => 'up{job="{nope}"}']]] + $m, 'metrics.queries.x.query'];
        yield 'metrics bad aggregate' => ['metrics', ['queries' => ['x' => ['query' => 'up', 'aggregate' => 'p99']]] + $m, 'metrics.queries.x'];
        yield 'metrics bad mode' => ['metrics', ['queries' => ['x' => ['query' => 'up', 'mode' => 'both']]] + $m, 'metrics.queries.x'];
        yield 'metrics bad threshold' => ['metrics', ['queries' => ['x' => ['query' => 'up', 'threshold' => ['relative' => 0]]]] + $m, 'metrics.queries.x'];
        yield 'metrics var with a quote' => ['metrics', ['vars' => ['ns' => 'a"b']] + $m, 'metrics.vars.ns'];
        yield 'metrics max_anomalies' => ['metrics', ['max_anomalies' => 11] + $m, 'metrics.max_anomalies'];
        yield 'metrics max_points' => ['metrics', ['max_points' => 5] + $m, 'metrics.max_points'];
        yield 'sentry unknown key' => ['sentry', $s + ['nope' => 1], 'sentry.nope'];
        yield 'sentry http' => ['sentry', ['base_url' => 'http://sentry.example.com'] + $s, 'sentry.base_url'];
        yield 'sentry RFC1918 without flag' => ['sentry', ['base_url' => 'https://192.168.1.5'] + $s, 'sentry.base_url'];
        yield 'sentry dotdot org' => ['sentry', ['organization' => '..'] + $s, 'sentry.organization'];
        yield 'sentry slash in project' => ['sentry', ['project' => 'a/b'] + $s, 'sentry.project'];
        yield 'sentry no token' => ['sentry', ['auth_token' => ''] + $s, 'sentry.auth_token'];
        yield 'sentry max_issues' => ['sentry', ['max_issues' => 101] + $s, 'sentry.max_issues'];
    }

    /** @param array<string, mixed> $section */
    #[DataProvider('bad')]
    public function testBadValuesNameTheKeyPathAndNeverAValue(string $key, array $section, string $path): void
    {
        try {
            WatchConfig::fromArray(self::config([$key => $section]));
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString($path, $e->getMessage());
            foreach ([self::PROM_TOKEN, self::SENTRY_TOKEN, 'a"b', 'u:p@'] as $secret) {
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    public function testPrivateNetworkNeedsTheOperatorsFlag(): void
    {
        $c = WatchConfig::fromArray(self::config([
            'metrics' => ['source' => ['base_url' => 'https://prometheus.monitoring.svc:9090', 'allow_private_network' => true] + self::metrics()['source']] + self::metrics(),
            'sentry' => ['base_url' => 'https://10.2.3.4', 'allow_private_network' => true] + self::sentry(),
        ]));
        self::assertTrue($c->metrics?->allowPrivateNetwork);
        self::assertTrue($c->sentry?->allowPrivateNetwork);
    }

    public function testSecretsHoldBothTokensLongestFirstInOneList(): void
    {
        $secrets = WatchConfig::fromArray(self::config(['metrics' => self::metrics(), 'sentry' => self::sentry()]))->secrets();
        self::assertContains(self::PROM_TOKEN, $secrets);
        self::assertContains(self::SENTRY_TOKEN, $secrets);
        $lengths = array_map('strlen', $secrets);
        $sorted = $lengths;
        rsort($sorted);
        self::assertSame($sorted, $lengths);
    }

    public function testNoDumpOfTheConfigOrItsSettingsHoldsATokenAndSerializeRefuses(): void
    {
        $c = WatchConfig::fromArray(self::config(['metrics' => self::metrics(), 'sentry' => self::sentry()]));
        foreach ([$c, $c->metrics, $c->sentry] as $o) {
            ob_start();
            var_dump($o);
            $dumps = [(string) ob_get_clean(), print_r($o, true), var_export($o, true), (string) json_encode($o)];
            foreach ($dumps as $d) {
                self::assertStringNotContainsString(self::PROM_TOKEN, $d);
                self::assertStringNotContainsString(self::SENTRY_TOKEN, $d);
            }
        }
        foreach ([$c->metrics, $c->sentry] as $o) {
            try {
                serialize($o);
                self::fail('serialize must refuse');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
