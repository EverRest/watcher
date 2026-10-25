<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Metrics;

use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Issues\SentryIssueSource;
use Watcher\Metrics\PrometheusSource;

/** The credential of a built source is in no dump of the object, and the shared SSRF classifier decides the base URL. */
final class SourceCredentialsTest extends TestCase
{
    private const CANARY = 'CANARY-tok-8c1f2e7a9b';

    /** @return iterable<string, array{object}> */
    public static function built(): iterable
    {
        yield 'prometheus' => [new PrometheusSource(new Client(), 'https://prom.invalid', self::CANARY)];
        yield 'grafana' => [new PrometheusSource(new Client(), 'https://grafana.invalid', self::CANARY, 'uid1')];
        yield 'sentry' => [new SentryIssueSource(new Client(), 'https://sentry.io', 'acme', 'api', self::CANARY)];
    }

    #[DataProvider('built')]
    public function testNoDumpOfTheSourceHoldsTheToken(object $source): void
    {
        ob_start();
        var_dump($source);
        $dump = (string) ob_get_clean();
        $dumps = [$dump, print_r($source, true), var_export($source, true), (string) json_encode($source)];
        try {
            $dumps[] = serialize($source);
        } catch (\Throwable $e) {
            $dumps[] = $e->getMessage();
        }
        foreach ($dumps as $d) {
            self::assertStringNotContainsString(self::CANARY, $d);
        }
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function urls(): iterable
    {
        yield 'rfc1918 refused by default' => ['https://10.0.0.5:9090', false, false];
        yield 'rfc1918 allowed with the flag' => ['https://10.0.0.5:9090', true, true];
        yield 'cluster-local refused by default' => ['https://prometheus.monitoring.svc:9090', false, false];
        yield 'cluster-local allowed with the flag' => ['https://prometheus.monitoring.svc:9090', true, true];
        yield 'metadata never' => ['https://169.254.169.254', true, false];
        yield 'cgnat never' => ['https://100.64.0.1', true, false];
        yield 'public' => ['https://prom.invalid', false, true];
    }

    #[DataProvider('urls')]
    public function testPrivateNetworksNeedTheFlag(string $url, bool $allowPrivate, bool $ok): void
    {
        foreach ([
            static fn () => new PrometheusSource(new Client(), $url, allowPrivateNetwork: $allowPrivate),
            static fn () => new SentryIssueSource(new Client(), $url, 'acme', 'api', 't', allowPrivateNetwork: $allowPrivate),
        ] as $build) {
            if (!$ok) {
                $this->expectException(InvalidConfig::class);
            }
            $build();
            $this->addToAssertionCount(1);
        }
    }
}
