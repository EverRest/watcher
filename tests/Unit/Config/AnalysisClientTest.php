<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnthropicAnalyzer;
use Watcher\Config\Factory;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

final class AnalysisClientTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function config(?string $client = null): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki', 'auth' => ['type' => 'bearer', 'token' => 'tok-secret']]],
            'analysis' => ['api_key' => 'sk-secret-key', 'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]]]
                + ($client === null ? [] : ['client' => $client]),
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/SECRET']],
        ];
    }

    public function testTheDefaultIsHttp(): void
    {
        $c = WatchConfig::fromArray(self::config());

        self::assertSame('http', $c->analysis['client']);
    }

    public function testHttpBuildsTheOwnClient(): void
    {
        $c = WatchConfig::fromArray(self::config('http'));

        self::assertInstanceOf(AnthropicAnalyzer::class, Factory::analyzer($c));
    }

    public function testSdkIsRefusedAtConfigLoadNamingTheReplacement(): void
    {
        try {
            WatchConfig::fromArray(self::config('sdk'));
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertSame('analysis.client is no longer a transport choice; the Anthropic transport is the official SDK. Delete the key.', $e->getMessage());
        }
    }

    public function testAnyOtherValueIsRefusedWithTheSameReplacement(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('analysis.client is no longer a transport choice');
        WatchConfig::fromArray(self::config('curl'));
    }

    public function testAbsentLoadsWithoutAnyNotice(): void
    {
        WatchConfig::fromArray(self::config());

        $this->expectNotToPerformAssertions();
    }
}
