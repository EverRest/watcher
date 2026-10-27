<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

/** 0.5.0 removes the budget and price keys: each fails at load with its exact message (contracts/config-migration.md). Red until US2. */
final class RemovedKeysTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function base(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.invalid', 'auth' => ['type' => 'bearer', 'token' => 'tok']]],
            'analysis' => ['api_key' => 'k', 'models' => ['triage' => 'a', 'analyze' => 'b', 'escalate' => 'c']],
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
}
