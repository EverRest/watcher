<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

/** Spec 009 T039: links.tempo and links.datadog_apm. */
final class TraceLinksConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $links
     *
     * @return array<string, mixed>
     */
    private static function config(array $links): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki']],
            'analysis' => ['api_key' => 'k', 'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'], 'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]]],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
            'links' => $links,
        ];
    }

    public function testBothAreAccepted(): void
    {
        $c = WatchConfig::fromArray(self::config([
            'tempo' => ['base_url' => 'https://grafana.example', 'datasource_uid' => 'tempo', 'trace_field' => 'traceId'],
            'datadog' => ['app_url' => 'https://app.datadoghq.com'],
            'datadog_apm' => ['service' => 'orders'],
        ]));

        self::assertSame('traceId', $c->links['tempo']['trace_field']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function bad(): iterable
    {
        yield 'unknown nested key' => [['tempo' => ['base_url' => 'https://g.example', 'datasource_uid' => 't', 'nope' => 1]], 'links.tempo.nope'];
        yield 'tempo without uid' => [['tempo' => ['base_url' => 'https://g.example']], 'links.tempo.datasource_uid'];
        yield 'tempo http url' => [['tempo' => ['base_url' => 'http://g.example', 'datasource_uid' => 't']], 'links.tempo.base_url'];
        yield 'bad trace_field' => [['tempo' => ['base_url' => 'https://g.example', 'datasource_uid' => 't', 'trace_field' => 'a b']], 'links.tempo.trace_field'];
        yield 'apm without datadog url' => [['datadog_apm' => []], 'links.datadog_apm requires links.datadog.app_url'];
        yield 'apm not a map' => [['datadog_apm' => 'x', 'datadog' => ['app_url' => 'https://a.example']], 'links.datadog_apm'];
    }

    /** @param array<string, mixed> $links */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad')]
    public function testBadValuesNameTheKeyPath(array $links, string $path): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage($path);
        WatchConfig::fromArray(self::config($links));
    }
}
