<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

/** 0.5.0 removes the budget and price keys, 0.7.0 removes Anthropic: each fails at load with its exact message (contracts/config-migration.md). */
final class RemovedKeysTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function base(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.invalid', 'auth' => ['type' => 'bearer', 'token' => 'tok']]],
            'analysis' => ['api_keys' => ['gemini' => 'k'], 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c']],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x']],
        ];
    }

    /** @param array<string, mixed> $config */
    private static function expectFailure(array $config, string $message): void
    {
        try {
            WatchConfig::fromArray($config);
            self::fail("expected InvalidConfig: $message");
        } catch (InvalidConfig $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public function testPricesFailWithTheRemovalMessage(): void
    {
        $c = self::base();
        $c['analysis']['prices'] = ['a' => [1, 5, 0.1, 1.25]];

        self::expectFailure($c, 'analysis.prices was removed in 0.5.0; there is no replacement. Delete this key.');
    }

    public function testDailyBudgetUsdFails(): void
    {
        $c = self::base();
        $c['analysis']['daily_budget_usd'] = 0.5;

        self::expectFailure($c, 'analysis.daily_budget_usd was removed in 0.5.0; there is no replacement. Delete this key.');
    }

    public function testRunBudgetUsdFails(): void
    {
        $c = self::base();
        $c['analysis']['run_budget_usd'] = 0.1;

        self::expectFailure($c, 'analysis.run_budget_usd was removed in 0.5.0; there is no replacement. Delete this key.');
    }

    public function testDailyTokenLimitFails(): void
    {
        $c = self::base();
        $c['analysis']['daily_token_limit'] = 100000;

        self::expectFailure($c, 'analysis.daily_token_limit was removed in 0.5.0; there is no replacement. Delete this key.');
    }

    public function testBudgetSectionFails(): void
    {
        $c = self::base();
        $c['budget'] = ['daily_budget_usd' => 1.0];

        self::expectFailure($c, 'budget was removed in 0.5.0; there is no replacement. Delete this section.');
    }

    public function testBudgetAlertPercentsFails(): void
    {
        $c = self::base();
        $c['analysis']['budget_alert_percents'] = [50, 80];

        self::expectFailure($c, 'analysis.budget_alert_percents was removed in 0.5.0; there is no replacement. Delete this key.');
    }

    public function testMaxRunsPerDayFails(): void
    {
        $c = self::base();
        $c['analysis']['max_runs_per_day'] = 3;

        self::expectFailure($c, 'analysis.max_runs_per_day was removed in 0.5.0; there is no replacement. Delete this key.');
    }

    public function testAnalysisApiKeyFailsAndNamesTheReplacement(): void
    {
        $c = self::base();
        $c['analysis']['api_key'] = 'k';

        self::expectFailure($c, 'analysis.api_key was removed in 0.7.0; use analysis.api_keys.gemini or analysis.api_keys.openai (the provider in use).');
    }

    public function testAnthropicApiKeyFailsAndNamesTheReplacement(): void
    {
        $c = self::base();
        $c['analysis']['api_keys']['anthropic'] = 'k';

        self::expectFailure($c, 'analysis.api_keys.anthropic was removed in 0.7.0; Anthropic is no longer a provider. Use analysis.api_keys.gemini or analysis.api_keys.openai.');
    }

    public function testAnthropicProvidersOptionsFailsAndSaysToDeleteIt(): void
    {
        $c = self::base();
        $c['analysis']['providers_options'] = ['anthropic' => ['retries' => 0]];

        self::expectFailure($c, 'analysis.providers_options.anthropic was removed in 0.7.0; Anthropic is no longer a provider. Delete this section.');
    }

    public function testAnthropicAsTheProviderFails(): void
    {
        $c = self::base();
        $c['analysis']['provider'] = 'anthropic';

        self::expectFailure($c, 'analysis.provider = anthropic was removed in 0.7.0; Anthropic is no longer a provider. Use gemini or openai.');
    }

    public function testAnthropicAsAStepProviderFails(): void
    {
        $c = self::base();
        $c['analysis']['providers'] = ['triage' => 'anthropic'];

        self::expectFailure($c, 'analysis.providers.triage = anthropic was removed in 0.7.0; Anthropic is no longer a provider. Use gemini or openai.');
    }

    public function testTheRemovedApiKeyFailsEvenBesideAValidGeminiConfig(): void
    {
        $c = self::base();
        $c['analysis']['provider'] = 'gemini';
        $c['analysis']['api_keys'] = ['gemini' => 'gm-secret'];
        $c['analysis']['api_key'] = 'sk-old-secret';

        self::expectFailure($c, 'analysis.api_key was removed in 0.7.0; use analysis.api_keys.gemini or analysis.api_keys.openai (the provider in use).');
    }

    public function testEveryRemovedKeyIsNamedInTableOrder(): void
    {
        $c = self::base();
        $c['analysis']['prices'] = ['a' => [1, 5, 0.1, 1.25]];
        $c['budget'] = ['daily_budget_usd' => 1.0];

        self::expectFailure($c, 'analysis.prices was removed in 0.5.0; there is no replacement. Delete this key.; budget was removed in 0.5.0; there is no replacement. Delete this section.');
    }

    public function testKubernetesSectionFailsWithTheRemovalMessageEvenWhenDisabled(): void
    {
        $c = self::base();
        $c['kubernetes'] = ['enabled' => false];

        self::expectFailure($c, 'kubernetes section was removed in 0.7.0; there is no replacement. Delete this section.');
    }

    public function testEventsSectionFails(): void
    {
        $c = self::base();
        $c['events'] = ['driver' => 'kubernetes'];

        self::expectFailure($c, 'events section was removed in 0.7.0; there is no replacement. Delete this section.');
    }

    public function testSourceDriverKubernetesFails(): void
    {
        $c = self::base();
        $c['source'] = ['driver' => 'kubernetes'];

        self::expectFailure($c, 'source.driver kubernetes was removed in 0.7.0; there is no replacement. Use a log source (loki or datadog). Delete the kubernetes settings.');
    }

    public function testSourceDriverLokiStillLoads(): void
    {
        $c = self::base();

        self::assertSame('loki', WatchConfig::fromArray($c)->source['driver']);
    }

    public function testEveryKubernetesRemovalIsNamedTogetherWithOtherRemovedKeys(): void
    {
        $c = self::base();
        $c['source'] = ['driver' => 'kubernetes'];
        $c['kubernetes'] = ['namespaces' => ['shop']];
        $c['events'] = ['driver' => 'kubernetes'];
        $c['analysis']['prices'] = ['a' => [1, 5, 0.1, 1.25]];

        self::expectFailure($c, 'analysis.prices was removed in 0.5.0; there is no replacement. Delete this key.; kubernetes section was removed in 0.7.0; there is no replacement. Delete this section.; events section was removed in 0.7.0; there is no replacement. Delete this section.; source.driver kubernetes was removed in 0.7.0; there is no replacement. Use a log source (loki or datadog). Delete the kubernetes settings.');
    }
}
