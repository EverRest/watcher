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

    public function testSdkIsRefusedAtConfigLoadWithAFixedMessage(): void
    {
        try {
            WatchConfig::fromArray(self::config('sdk'));
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertSame('analysis.client: the SDK client was removed; use analysis.client=http (the default)', $e->getMessage());
        }
    }

    public function testAnUnknownClientIsRejected(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('analysis.client must be http');
        WatchConfig::fromArray(self::config('curl'));
    }
}
