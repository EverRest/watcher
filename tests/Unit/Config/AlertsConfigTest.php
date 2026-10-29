<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\Level;

/** alerts.immediate: off by default, rules validated at load time. */
final class AlertsConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $alerts
     *
     * @return array<string, mixed>
     */
    private static function config(array $alerts = []): array
    {
        return [
            'service' => 'm-seq',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki']],
            'analysis' => ['api_keys' => ['gemini' => 'k'], 'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3']],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
            'alerts' => $alerts,
        ];
    }

    public function testOffByDefaultWithNoRules(): void
    {
        $c = WatchConfig::fromArray(self::config());

        self::assertFalse($c->on('alerts'));
        self::assertSame(['window' => 900, 'fields' => [], 'immediate' => []], $c->alerts);
    }

    public function testRuleWithDefaultLevelAndWindow(): void
    {
        $c = WatchConfig::fromArray(self::config([
            'enabled' => true,
            'immediate' => ['health-check' => ['message' => 'health check failed']],
        ]));

        self::assertTrue($c->on('alerts'));
        self::assertSame(900, $c->alerts['window']);
        self::assertSame(['level' => Level::Critical, 'message' => 'health check failed', 'action' => null], $c->alerts['immediate']['health-check']);
    }

    public function testWindowIsAnIsoDuration(): void
    {
        $c = WatchConfig::fromArray(self::config(['enabled' => true, 'window' => 'PT1H']));

        self::assertSame(3600, $c->alerts['window']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'window not a duration' => [['window' => 'soon']];
        yield 'window zero' => [['window' => 'PT0S']];
        yield 'immediate as a list' => [['immediate' => [['level' => 'critical']]]];
        yield 'unknown level name' => [['immediate' => ['x' => ['level' => 'loud']]]];
        yield 'empty message' => [['immediate' => ['x' => ['message' => '']]]];
        yield 'unknown rule key' => [['immediate' => ['x' => ['lvl' => 'critical']]]];
        yield 'enabled not a boolean' => [['enabled' => 'yes']];
        yield 'fields not a list' => [['fields' => 'service']];
        yield 'field outside the allowlist' => [['fields' => ['password']]];
    }

    /** @param array<string, mixed> $alerts */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testBadRulesAreRefusedAtLoad(array $alerts): void
    {
        $this->expectException(InvalidConfig::class);

        WatchConfig::fromArray(self::config($alerts));
    }
}
