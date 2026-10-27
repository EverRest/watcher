<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

/** Per-step providers, API keys per provider and provider options (spec 016, contracts/analysis-config.md). */
final class WatchConfigProvidersTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki', 'auth' => ['type' => 'bearer', 'token' => 'tok-secret']]],
            'analysis' => [
                'api_key' => 'sk-anthropic-secret',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/SECRET']],
        ];
    }

    /**
     * @param array<string, mixed> $analysis
     *
     * @return array<string, mixed>
     */
    private static function withAnalysis(array $analysis): array
    {
        $c = self::valid();
        $c['analysis'] = array_replace_recursive($c['analysis'], $analysis);

        return $c;
    }

    public function testStepProviderFallsBackToTheDefaultProvider(): void
    {
        $c = WatchConfig::fromArray(self::valid());

        self::assertSame(['triage' => 'anthropic', 'analyze' => 'anthropic', 'escalate' => 'anthropic'], $c->analysis['step_providers']);
    }

    public function testOneStepCanUseAnotherProvider(): void
    {
        $c = WatchConfig::fromArray(self::withAnalysis([
            'api_keys' => ['gemini' => 'gm-secret-key'],
            'providers' => ['triage' => 'gemini'],
            'models' => ['triage' => 'm1'],
        ]));

        self::assertSame(['triage' => 'gemini', 'analyze' => 'anthropic', 'escalate' => 'anthropic'], $c->analysis['step_providers']);
    }

    public function testUnknownStepKeyIsRefused(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('analysis.providers');
        WatchConfig::fromArray(self::withAnalysis(['providers' => ['rerank' => 'anthropic']]));
    }

    public function testUnknownProviderNameIsRefused(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('analysis.providers.triage');
        WatchConfig::fromArray(self::withAnalysis(['providers' => ['triage' => 'mistral']]));
    }

    public function testUsedProviderWithoutKeyNamesTheKeyPathNotTheValue(): void
    {
        try {
            WatchConfig::fromArray(self::withAnalysis(['providers' => ['triage' => 'openai'], 'models' => ['triage' => 'm1']]));
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString('analysis.api_keys.openai', $e->getMessage());
            self::assertStringNotContainsString('sk-anthropic-secret', $e->getMessage());
        }
    }

    public function testApiKeyIsTheAnthropicKeyForCompatibility(): void
    {
        $c = WatchConfig::fromArray(self::valid());

        self::assertSame('sk-anthropic-secret', WatchConfig::reveal($c->analysis['api_keys']['anthropic']));
    }

    public function testBaseUrlMustBeHttps(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('analysis.providers_options.openai.base_url');
        WatchConfig::fromArray(self::withAnalysis([
            'api_keys' => ['openai' => 'sk-openai'],
            'providers' => ['triage' => 'openai'],
            'providers_options' => ['openai' => ['base_url' => 'http://insecure.example']],
        ]));
    }

    public function testRetriesDefaultToZeroAndAreBounded(): void
    {
        $c = WatchConfig::fromArray(self::valid());
        self::assertSame(0, $c->analysis['providers_options']['anthropic']['retries']);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('analysis.providers_options.anthropic.retries');
        WatchConfig::fromArray(self::withAnalysis(['providers_options' => ['anthropic' => ['retries' => 4]]]));
    }

    public function testAStepModelMissingFromPricesFailsAtLoad(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('analysis.prices');
        WatchConfig::fromArray(self::withAnalysis(['models' => ['escalate' => 'not-priced']]));
    }
}
