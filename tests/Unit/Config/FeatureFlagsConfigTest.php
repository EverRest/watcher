<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

final class FeatureFlagsConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki']],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],

            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
        ];
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private static function with(array $patch): array
    {
        return array_replace_recursive(self::valid(), $patch);
    }

    public function testMasterSwitchIsOffByDefaultAndEveryOtherFlagIsOn(): void
    {
        $c = WatchConfig::fromArray(self::valid());

        self::assertFalse($c->enabled);
        foreach (WatchConfig::FLAGS as $flag) {
            if ($flag === 'drafts') {
                self::assertFalse($c->on($flag));
                continue;
            }
            self::assertTrue($c->on($flag), $flag);
        }
        self::assertFalse($c->heartbeat['weekly_summary']);
        self::assertNull($c->environment);
        self::assertSame('svc', $c->stateKey);
        self::assertTrue(WatchConfig::fromArray(self::with(['enabled' => true]))->enabled);
    }

    public function testEnvironmentBecomesPartOfTheStateKey(): void
    {
        $c = WatchConfig::fromArray(self::with(['environment' => 'int']));

        self::assertSame('int', $c->environment);
        self::assertSame('svc@int', $c->stateKey);
        self::assertSame('svc', $c->service);
    }

    public function testEnvironmentMustBeAPlainName(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('environment');
        WatchConfig::fromArray(self::with(['environment' => 'in t']));
    }

    /** @return array<string, array{array<string, mixed>, string, string}> flag patch, flag name, config path */
    public static function flags(): array
    {
        return [
            'heartbeat' => [['heartbeat' => ['enabled' => false]], 'heartbeat', 'heartbeat.enabled'],
            'purge' => [['purge' => ['enabled' => false]], 'purge', 'purge.enabled'],
            'dependencies' => [['dependencies' => ['enabled' => false]], 'dependencies', 'dependencies.enabled'],
            'deploy' => [['deploy' => ['enabled' => false]], 'deploy', 'deploy.enabled'],
            'links.logs' => [['links' => ['logs' => ['enabled' => false]]], 'links.logs', 'links.logs.enabled'],
            'links.code' => [['links' => ['code' => ['enabled' => false]]], 'links.code', 'links.code.enabled'],
            'service_context' => [['service_context' => ['enabled' => false, 'text' => 'x']], 'service_context', 'service_context.enabled'],
            'watch_rules' => [['watch_rules' => ['enabled' => false]], 'watch_rules', 'watch_rules.enabled'],
            'mute' => [['mute' => ['enabled' => false]], 'mute', 'mute.enabled'],
            'level_anomaly' => [['level_anomaly' => ['enabled' => false]], 'level_anomaly', 'level_anomaly.enabled'],
            'escalation' => [['selection' => ['escalation' => ['enabled' => false]]], 'escalation', 'selection.escalation.enabled'],
            'triage' => [['analysis' => ['triage' => ['enabled' => false]]], 'triage', 'analysis.triage.enabled'],
            'advice' => [['analysis' => ['advice' => ['enabled' => false]]], 'advice', 'analysis.advice.enabled'],
            'baseline' => [['baseline' => ['enabled' => false]], 'baseline', 'baseline.enabled'],
            'grouping' => [['grouping' => ['enabled' => false]], 'grouping', 'grouping.enabled'],
            'notice' => [['notify' => ['notice' => ['enabled' => false]]], 'notice', 'notify.notice.enabled'],
            'digest' => [['notify' => ['digest' => ['enabled' => false]]], 'digest', 'notify.digest.enabled'],
            'known_issues' => [['known_issues' => ['enabled' => false]], 'known_issues', 'known_issues.enabled'],
            'drafts' => [['drafts' => ['enabled' => false]], 'drafts', 'drafts.enabled'],
            'routes' => [['notify' => ['routes_enabled' => false]], 'routes', 'notify.routes_enabled'],
        ];
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('flags')]
    public function testEachFlagCanBeSwitchedOff(array $patch, string $flag, string $path): void
    {
        $c = WatchConfig::fromArray(self::with($patch));

        self::assertFalse($c->on($flag), $path);
        foreach (WatchConfig::FLAGS as $other) {
            if ($other !== $flag && $other !== 'drafts') {
                self::assertTrue($c->on($other), "$other must stay on when only $flag is off");
            }
        }
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('flags')]
    public function testAFlagMustBeARealBooleanAndTheMessageNamesThePath(array $patch, string $flag, string $path): void
    {
        if ($flag === 'routes') {
            try {
                WatchConfig::fromArray(self::with(['notify' => ['routes_enabled' => 'false']]));
                self::fail('expected InvalidConfig');
            } catch (InvalidConfig $e) {
                self::assertStringContainsString($path, $e->getMessage());
            }

            return;
        }
        $bad = json_decode((string) json_encode($patch), true);
        array_walk_recursive($bad, static function (&$v, $k): void {
            if ($k === 'enabled') {
                $v = 'false';
            }
        });

        try {
            WatchConfig::fromArray(self::with($bad));
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString($path, $e->getMessage());
        }
    }

    public function testMasterAndWeeklySummaryAreStrictBooleansToo(): void
    {
        foreach ([['enabled' => 'true'], ['enabled' => 1], ['heartbeat' => ['weekly_summary' => 'yes']]] as $patch) {
            try {
                WatchConfig::fromArray(self::with($patch));
                self::fail('expected InvalidConfig');
            } catch (InvalidConfig $e) {
                self::assertMatchesRegularExpression('/^(enabled|heartbeat\.weekly_summary) must be true or false$/', $e->getMessage());
            }
        }
    }

    public function testNoticeFlagAloneUsesTheDigestDestination(): void
    {
        $c = WatchConfig::fromArray(self::with(['notify' => ['notice' => ['enabled' => false]]]));

        self::assertTrue($c->destination('notice')->sameAs($c->destination('digest')));
    }

    public function testDisabledWatchRulesAndMuteAreEmptyAndStillValidated(): void
    {
        $c = WatchConfig::fromArray(self::with(['watch_rules' => ['enabled' => false, 'q' => ['template' => '/x/']], 'mute' => ['enabled' => false, ['fingerprint' => 'abc']]]));

        self::assertSame([], $c->watchRules);
        self::assertSame([], $c->mute);
        $this->expectException(InvalidConfig::class);
        WatchConfig::fromArray(self::with(['mute' => ['enabled' => false, ['template' => '(bad']]]));
    }

    public function testEnabledKeyDoesNotBreakEnabledWatchRulesAndMute(): void
    {
        $c = WatchConfig::fromArray(self::with(['watch_rules' => ['q' => ['template' => '/x/']], 'mute' => [['fingerprint' => 'abc']]]));

        self::assertSame(['q'], array_keys($c->watchRules));
        self::assertCount(1, $c->mute);
    }

    public function testServiceContextStillAcceptsAPlainString(): void
    {
        self::assertSame('hello', WatchConfig::fromArray(self::with(['service_context' => 'hello']))->serviceContext);
        self::assertSame('hello', WatchConfig::fromArray(self::with(['service_context' => ['text' => 'hello']]))->serviceContext);
    }

    public function testThereIsNoSwitchForRedactionOrReadOnly(): void
    {
        foreach (['redaction' => ['enabled' => false], 'redact' => false, 'privacy' => ['enabled' => false], 'normalizer' => ['enabled' => false], 'allowlist' => ['enabled' => false]] as $key => $value) {
            try {
                WatchConfig::fromArray(self::with([$key => $value]));
                self::fail("$key must be rejected");
            } catch (InvalidConfig $e) {
                self::assertStringContainsString('Unknown configuration key', $e->getMessage());
            }
        }
        foreach (['read_only' => false, 'enabled' => false] as $key => $value) {
            if ($key === 'read_only') {
                $this->expectException(InvalidConfig::class);
                WatchConfig::fromArray(self::with(['source' => [$key => $value]]));
            }
        }
    }

    public function testNoFlagNameMatchesPrivacyMachinery(): void
    {
        foreach (WatchConfig::FLAGS as $flag) {
            self::assertDoesNotMatchRegularExpression('/redact|normaliz|allowlist|privacy|scrub|read_only/i', $flag);
        }
    }

    public function testAnalysisEnabledIsNotAvailable(): void
    {
        $this->expectException(InvalidConfig::class);
        WatchConfig::fromArray(self::with(['analysis' => ['enabled' => false]]));
    }

    /** @param array<string, string> $env */
    private static function withEnv(array $env, \Closure $fn): void
    {
        $before = [];
        foreach ($env as $k => $v) {
            $before[$k] = getenv($k);
            putenv("$k=$v");
        }
        try {
            $fn();
        } finally {
            foreach ($before as $k => $v) {
                putenv($v === false ? $k : "$k=$v");
            }
        }
    }

    public function testThePublishableConfigIsSwitchedByEnvironmentVariables(): void
    {
        $base = [
            'WATCHER_SERVICE' => 'svc', 'WATCHER_RUN_AT' => '06:00', 'WATCHER_LOKI_URL' => 'https://loki', 'WATCHER_ANTHROPIC_KEY' => 'k',
            'WATCHER_MODEL_TRIAGE' => 'm1', 'WATCHER_MODEL_ANALYZE' => 'm2', 'WATCHER_MODEL_ESCALATE' => 'm3', 'WATCHER_SLACK_WEBHOOK' => 'https://hooks.example/x',
            'WATCHER_STATE' => 'file', 'WATCHER_LOKI_TOKEN' => 't', 'WATCHER_REPO_URL' => 'https://git.example.com/g/p',
        ];
        $load = static function (): WatchConfig {
            $c = require __DIR__ . '/../../../config/watcher.php';

            return WatchConfig::fromArray($c);
        };

        self::withEnv($base, function () use ($load): void {
            $c = $load();
            self::assertFalse($c->enabled, 'off unless WATCHER_ENABLED');
            foreach (WatchConfig::FLAGS as $flag) {
                if ($flag === 'drafts') {
                    self::assertFalse($c->on($flag));
                } else {
                    self::assertTrue($c->on($flag), $flag);
                }
            }
        });
        $off = [];
        foreach (['HEARTBEAT', 'PURGE', 'DEPENDENCIES', 'DEPLOY', 'LINKS_LOGS', 'LINKS_CODE', 'SERVICE_CONTEXT', 'WATCH_RULES', 'MUTE', 'LEVEL_ANOMALY', 'ESCALATION', 'TRIAGE', 'ADVICE', 'BASELINE', 'GROUPING', 'BUDGET_ALERTS', 'NOTICE', 'DIGEST', 'KNOWN_ISSUES', 'DRAFTS', 'ROUTES'] as $name) {
            $off["WATCHER_{$name}_ENABLED"] = 'false';
        }
        self::withEnv($base + $off + ['WATCHER_ENABLED' => 'yes', 'WATCHER_ENVIRONMENT' => 'int'], function () use ($load): void {
            $c = $load();
            self::assertTrue($c->enabled);
            self::assertSame('svc@int', $c->stateKey);
            foreach (WatchConfig::FLAGS as $flag) {
                self::assertFalse($c->on($flag), $flag);
            }
        });
        self::withEnv($base + ['WATCHER_ENABLED' => 'maybe'], function () use ($load): void {
            $this->expectException(InvalidConfig::class);
            $this->expectExceptionMessage('WATCHER_ENABLED');
            $load();
        });
    }
}
