<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Source\KubernetesEventsSource;
use Watcher\Source\LokiSource;
use Watcher\Tests\Fakes\FrozenClock;

final class FactoryKubernetesTest extends TestCase
{
    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private static function config(array $patch = []): array
    {
        return array_replace_recursive([
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example'], 'stream_tags' => ['app' => 'svc']],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
        ], $patch);
    }

    private static function clock(): FrozenClock
    {
        return new FrozenClock(new \DateTimeImmutable('2026-10-24T06:00:00Z'));
    }

    /** @return array<string, mixed> */
    private static function kube(string $token = 't1', string $selector = 'app=api'): array
    {
        return ['kubernetes' => ['namespaces' => ['shop'], 'label_selector' => $selector, 'api' => ['server' => 'https://k8s.example', 'token' => $token]], 'events' => ['driver' => 'kubernetes']];
    }

    public function testNoEventsDeclaredMeansNoSource(): void
    {
        $c = WatchConfig::fromArray(self::config());
        $apis = [];

        self::assertNull(Factory::events($c, new Client(), self::clock(), $apis));
        self::assertSame([], $apis);
    }

    public function testTheFlagOffMeansNoSource(): void
    {
        $on = WatchConfig::fromArray(self::config(self::kube()));
        $off = WatchConfig::fromArray(self::config(array_replace_recursive(self::kube(), ['kubernetes' => ['enabled' => false]])));
        $apis = [];

        self::assertInstanceOf(KubernetesEventsSource::class, Factory::events($on, new Client(), self::clock(), $apis));
        $none = [];
        self::assertNull(Factory::events($off, new Client(), self::clock(), $none));
        self::assertSame([], $none);
    }

    public function testEntriesWithTheSameCredentialsShareOneClientAndOthersDoNot(): void
    {
        $apis = [];
        $http = new Client();
        Factory::events(WatchConfig::fromArray(self::config(self::kube('t1', 'app=api'))), $http, self::clock(), $apis);
        Factory::events(WatchConfig::fromArray(self::config(self::kube('t1', 'app=bff'))), $http, self::clock(), $apis);
        self::assertCount(1, $apis);

        Factory::events(WatchConfig::fromArray(self::config(self::kube('t2'))), $http, self::clock(), $apis);
        self::assertCount(2, $apis);
    }

    public function testKubernetesAsTheOnlySource(): void
    {
        $patch = self::kube();
        unset($patch['events']);
        $patch['source'] = ['driver' => 'kubernetes'];
        $c = WatchConfig::fromArray(array_replace(self::config($patch), ['source' => ['driver' => 'kubernetes']]));

        self::assertInstanceOf(KubernetesEventsSource::class, Factory::source($c, new Client(), self::clock()));
        self::assertNull(Factory::events($c, new Client(), self::clock()), 'no second instance: it is the main source');
    }

    public function testMainDriverEntriesShareTheClientWithExtraEventsEntries(): void
    {
        $apis = [];
        $http = new Client();
        $main = self::kube('t1');
        unset($main['events']);
        $mainConfig = array_replace(self::config($main), ['source' => ['driver' => 'kubernetes']]);
        Factory::source(WatchConfig::fromArray($mainConfig), $http, self::clock(), $apis);
        Factory::events(WatchConfig::fromArray(self::config(self::kube('t1', 'app=bff'))), $http, self::clock(), $apis);

        self::assertCount(1, $apis);
    }

    public function testOtherDriversAreUnchanged(): void
    {
        self::assertInstanceOf(LokiSource::class, Factory::source(WatchConfig::fromArray(self::config()), new Client(), self::clock()));
    }

    public function testTheTokenIsMaskedInStoredFailureReasons(): void
    {
        $c = WatchConfig::fromArray(self::config(self::kube('TOKEN-VALUE')));

        self::assertSame(['TOKEN-VALUE'], $c->kubernetes?->maskValues());
        self::assertContains('TOKEN-VALUE', $c->secrets(), 'one list for the heartbeat reason, the fail notice and the NoticeRenderer');
    }
}
