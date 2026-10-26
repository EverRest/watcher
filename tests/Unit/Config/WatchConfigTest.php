<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\Level;

final class WatchConfigTest extends TestCase
{
    private const SECRETS = ['sk-secret-key', 'https://hooks.example/SECRET', 'tok-secret', 'app-secret'];

    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => [
                'driver' => 'loki',
                'loki' => ['url' => 'https://loki', 'auth' => ['type' => 'bearer', 'token' => 'tok-secret']],
            ],
            'analysis' => [
                'api_key' => 'sk-secret-key',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/SECRET']],
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

    /**
     * @param array<mixed> $price
     * @return array<string, mixed>
     */
    private static function withPrice(string $model, array $price): array
    {
        $c = self::valid();
        $c['analysis']['prices'][$model] = $price;

        return $c;
    }

    public function testMinimalConfigGetsDefaults(): void
    {
        $c = WatchConfig::fromArray(self::valid());

        self::assertSame('svc', $c->service);
        self::assertSame(['06:00'], $c->schedule['run_at']);
        self::assertSame('UTC', $c->schedule['timezone']);
        self::assertSame('PT24H', $c->schedule['window']);
        self::assertSame(900, $c->schedule['lock_ttl']);
        self::assertSame(Level::Error, $c->selection['min_level']);
        self::assertSame(3.0, $c->selection['growth_factor']);
        self::assertSame(50, $c->selection['top_n']);
        self::assertSame(10, $c->selection['top_m']);
        self::assertSame(Level::Warning, $c->source['read_min_level']);
        self::assertSame('text', $c->source['level_mode']);
        self::assertSame(200000, $c->source['event_cap']);
        self::assertSame(16, $c->source['max_response_mb']);
        self::assertSame(20000, $c->source['anomaly_cap']);
        self::assertSame(5000, $c->source['group_cap']);
        self::assertSame(0.6, $c->analysis['confidence_threshold']);
        self::assertSame(3, $c->analysis['escalation_cap']);
        self::assertNull($c->analysis['daily_token_limit']);
        self::assertSame([25, 50, 75, 90], $c->analysis['budget_alert_percents']);
        self::assertSame([3.0, 15.0, 0.3, 3.75], $c->analysis['prices']['m2']);
        self::assertSame(2592000, $c->state['retention']);
        self::assertSame(5000, $c->dependencyReadCap);
        self::assertSame(['svc.cluster.local'], $c->dependencyDomains);
        self::assertSame([], $c->watchRules);
        self::assertSame([], $c->mute);
        self::assertSame(WatchConfig::plain($c->notify['digest']), WatchConfig::plain($c->notify['notice']));
    }

    public function testZeroBudgetAndEveryLevelModeAreAccepted(): void
    {
        foreach (['label', 'text', 'all'] as $mode) {
            self::assertSame($mode, WatchConfig::fromArray(self::with(['source' => ['level_mode' => $mode]]))->source['level_mode']);
        }
        self::assertSame(0.0, WatchConfig::fromArray(self::with(['analysis' => ['daily_budget_usd' => 0]]))->analysis['daily_budget_usd']);
    }

    public function testBudgetAlertOverridesAreAccepted(): void
    {
        $c = WatchConfig::fromArray(self::with(['analysis' => ['daily_token_limit' => 100000, 'budget_alert_percents' => [10, 99]]]));

        self::assertSame(100000, $c->analysis['daily_token_limit']);
        self::assertSame([10, 99], $c->analysis['budget_alert_percents']);
    }

    public function testExplicitValuesOverrideDefaults(): void
    {
        $c = WatchConfig::fromArray(self::with([
            'schedule' => ['timezone' => 'Europe/Kyiv', 'window' => 'PT12H'],
            'selection' => ['min_level' => 'critical', 'growth_factor' => 2.5, 'top_n' => 20, 'top_m' => 20],
            'notify' => ['notice' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/other']],
            'service_context' => 'short',
        ]));

        self::assertSame('Europe/Kyiv', $c->schedule['timezone']);
        self::assertSame(Level::Critical, $c->selection['min_level']);
        self::assertSame(2.5, $c->selection['growth_factor']);
        self::assertSame('https://hooks.example/other', WatchConfig::reveal($c->notify['notice']['webhook']));
        self::assertSame('short', $c->serviceContext);
    }

    public function testHeartbeatDefaultsToOneAndAHalfWindowsAndNoWeeklySummary(): void
    {
        $c = WatchConfig::fromArray(self::valid());
        self::assertSame(['stale_after' => 129600, 'weekly_summary' => false], $c->heartbeat);

        $c = WatchConfig::fromArray(self::with(['schedule' => ['window' => 'PT1H']]));
        self::assertSame(5400, $c->heartbeat['stale_after']);
    }

    public function testHeartbeatExplicitValues(): void
    {
        $c = WatchConfig::fromArray(self::with(['heartbeat' => ['stale_after' => 'PT2H', 'weekly_summary' => true]]));

        self::assertSame(['stale_after' => 7200, 'weekly_summary' => true], $c->heartbeat);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidCases(): array
    {
        $unset = static function (array $path): array {
            $c = self::valid();
            $ref = &$c;
            foreach (array_slice($path, 0, -1) as $k) {
                $ref = &$ref[$k];
            }
            unset($ref[end($path)]);

            return $c;
        };
        $badRegex = '/(unclosed/';

        return [
            'missing service' => [$unset(['service']), 'service'],
            'missing run_at' => [$unset(['schedule', 'run_at']), 'schedule.run_at'],
            'missing source driver' => [$unset(['source', 'driver']), 'source.driver'],
            'missing loki url' => [$unset(['source', 'loki', 'url']), 'source.loki.url'],
            'missing api_key' => [$unset(['analysis', 'api_key']), 'analysis.api_key'],
            'missing triage model' => [$unset(['analysis', 'models', 'triage']), 'analysis.models.triage'],
            'missing analyze model' => [$unset(['analysis', 'models', 'analyze']), 'analysis.models.analyze'],
            'missing escalate model' => [$unset(['analysis', 'models', 'escalate']), 'analysis.models.escalate'],
            'null model (unset env)' => [self::with(['analysis' => ['models' => ['triage' => null]]]), 'analysis.models.triage'],
            'missing price for triage model' => [self::with(['analysis' => ['models' => ['triage' => 'unpriced']]]), 'analysis.prices (no price for models.triage)'],
            'price with 3 numbers' => [self::withPrice('m1', [1, 2, 3]), 'analysis.prices.m1'],
            'negative price' => [self::withPrice('m1', [1, -2, 3, 4]), 'analysis.prices.m1'],
            'non-numeric price' => [self::withPrice('m1', [1, 'x', 3, 4]), 'analysis.prices.m1'],
            'zero token limit' => [self::with(['analysis' => ['daily_token_limit' => 0]]), 'analysis.daily_token_limit'],
            'float token limit' => [self::with(['analysis' => ['daily_token_limit' => 1.5]]), 'analysis.daily_token_limit'],
            'percent 0' => [self::with(['analysis' => ['budget_alert_percents' => [0, 50]]]), 'analysis.budget_alert_percents'],
            'percent 100' => [self::with(['analysis' => ['budget_alert_percents' => [50, 100]]]), 'analysis.budget_alert_percents'],
            'percents not ascending' => [self::with(['analysis' => ['budget_alert_percents' => [50, 25]]]), 'analysis.budget_alert_percents'],
            'percents duplicate' => [self::with(['analysis' => ['budget_alert_percents' => [25, 25]]]), 'analysis.budget_alert_percents'],
            'percents not ints' => [self::with(['analysis' => ['budget_alert_percents' => [25.5]]]), 'analysis.budget_alert_percents'],
            'missing state driver' => [$unset(['state', 'driver']), 'state.driver'],
            'missing file path' => [$unset(['state', 'file', 'path']), 'state.file.path'],
            'pdo without dsn' => [self::with(['state' => ['driver' => 'pdo']]), 'state.pdo.dsn'],
            'datadog without keys' => [self::with(['source' => ['driver' => 'datadog']]), 'source.datadog.api_key'],
            'missing webhook' => [$unset(['notify', 'digest', 'webhook']), 'notify.digest.webhook'],
            'unknown source driver' => [self::with(['source' => ['driver' => 'nope']]), 'source.driver'],
            'heartbeat not a map' => [self::with(['heartbeat' => 'on']), 'heartbeat'],
            'heartbeat stale_after not ISO' => [self::with(['heartbeat' => ['stale_after' => '36h']]), 'heartbeat.stale_after'],
            'heartbeat stale_after zero' => [self::with(['heartbeat' => ['stale_after' => 'PT0S']]), 'heartbeat.stale_after'],
            'heartbeat stale_after int' => [self::with(['heartbeat' => ['stale_after' => 3600]]), 'heartbeat.stale_after'],
            'heartbeat weekly_summary not bool' => [self::with(['heartbeat' => ['weekly_summary' => 'yes']]), 'heartbeat.weekly_summary'],
            'unknown heartbeat key' => [self::with(['heartbeat' => ['stale_afte' => 'PT1H']]), 'heartbeat.stale_afte'],
            'unknown top-level key' => [self::with(['analisys' => []]), 'analisys'],
            'growth_factor = 1' => [self::with(['selection' => ['growth_factor' => 1]]), 'selection.growth_factor'],
            'confidence 0' => [self::with(['analysis' => ['confidence_threshold' => 0]]), 'analysis.confidence_threshold'],
            'confidence 1' => [self::with(['analysis' => ['confidence_threshold' => 1]]), 'analysis.confidence_threshold'],
            'top_m > top_n' => [self::with(['selection' => ['top_n' => 5, 'top_m' => 6]]), 'selection.top_m'],
            'zero top_n' => [self::with(['selection' => ['top_n' => 0]]), 'selection.top_n'],
            'negative retention' => [self::with(['state' => ['retention' => -1]]), 'state.retention'],
            'zero group_cap' => [self::with(['source' => ['group_cap' => 0]]), 'source.group_cap'],
            'max_response_mb 0' => [self::with(['source' => ['max_response_mb' => 0]]), 'source.max_response_mb'],
            'max_response_mb 65' => [self::with(['source' => ['max_response_mb' => 65]]), 'source.max_response_mb'],
            'string event_cap' => [self::with(['source' => ['event_cap' => '10']]), 'source.event_cap'],
            'bad run_at' => [self::with(['schedule' => ['run_at' => '25:00']]), 'schedule.run_at'],
            'bad timezone' => [self::with(['schedule' => ['timezone' => 'Mars/Base']]), 'schedule.timezone'],
            'bad window' => [self::with(['schedule' => ['window' => '24 hours']]), 'schedule.window'],
            'zero window' => [self::with(['schedule' => ['window' => 'PT0S']]), 'schedule.window'],
            'bad min_level type' => [self::with(['selection' => ['min_level' => 5]]), 'selection.min_level'],
            'bad watch rule regex' => [self::with(['watch_rules' => ['q' => ['template' => $badRegex]]]), 'watch_rules.q.template'],
            'watch rule two matchers' => [self::with(['watch_rules' => ['q' => ['template' => '/a/', 'error_type' => '/b/']]]), 'watch_rules.q'],
            'watch rule no matcher' => [self::with(['watch_rules' => ['q' => []]]), 'watch_rules.q'],
            'field without comparison' => [self::with(['watch_rules' => ['q' => ['field' => 'tenant']]]), 'watch_rules.q'],
            'field with both' => [self::with(['watch_rules' => ['q' => ['field' => 't', 'equals' => 'a', 'matches' => '/a/']]]), 'watch_rules.q'],
            'field bad matches' => [self::with(['watch_rules' => ['q' => ['field' => 't', 'matches' => $badRegex]]]), 'watch_rules.q.matches'],
            'mute bad regex' => [self::with(['mute' => [['template' => $badRegex, 'until' => null]]]), 'mute.0.template'],
            'mute bad until' => [self::with(['mute' => [['fingerprint' => 'abc', 'until' => 'soon']]]), 'mute.0.until'],
            'mute no matcher' => [self::with(['mute' => [['until' => null]]]), 'mute.0'],
            'bad redaction regex' => [self::with(['redaction' => ['extra_patterns' => ['x' => $badRegex]]]), 'redaction.extra_patterns.x'],
            'reserved redaction name' => [self::with(['redaction' => ['extra_patterns' => ['email' => '/x/']]]), 'redaction.extra_patterns'],
            'min_group_events_for_text zero' => [self::with(['redaction' => ['min_group_events_for_text' => 0]]), 'redaction.min_group_events_for_text'],
            'min_group_events_for_text negative' => [self::with(['redaction' => ['min_group_events_for_text' => -2]]), 'redaction.min_group_events_for_text'],
            'min_group_events_for_text string' => [self::with(['redaction' => ['min_group_events_for_text' => '3']]), 'redaction.min_group_events_for_text'],
            'min_group_events_for_text float' => [self::with(['redaction' => ['min_group_events_for_text' => 2.5]]), 'redaction.min_group_events_for_text'],
            'dependency source override' => [self::with(['dependencies' => ['core-api' => ['stream_tags' => ['app' => 'core-api'], 'source' => 'other']]]), 'dependencies.core-api.source'],
            'unknown schedule key' => [self::with(['schedule' => ['run_att' => '06:00']]), 'schedule.run_att'],
            'unknown source key' => [self::with(['source' => ['evnt_cap' => 5]]), 'source.evnt_cap'],
            'sample_size is gone' => [self::with(['source' => ['sample_size' => 3]]), 'source.sample_size'],
            'unknown loki key' => [self::with(['source' => ['loki' => ['urll' => 'x']]]), 'source.loki.urll'],
            'unknown loki auth key' => [self::with(['source' => ['loki' => ['auth' => ['type' => 'bearer', 'token' => 't', 'tokn' => 'x']]]]), 'source.loki.auth.tokn'],
            'unknown datadog key' => [self::with(['source' => ['driver' => 'datadog', 'datadog' => ['api_key' => 'a', 'app_key' => 'b', 'sit' => 'x']]]), 'source.datadog.sit'],
            'unknown selection key' => [self::with(['selection' => ['min_levl' => 'error']]), 'selection.min_levl'],
            'unknown analysis key' => [self::with(['analysis' => ['daily_budget' => 1]]), 'analysis.daily_budget'],
            'unknown analysis models key' => [self::with(['analysis' => ['models' => ['triag' => 'x']]]), 'analysis.models.triag'],
            'unknown max_input_tokens key' => [self::with(['analysis' => ['max_input_tokens' => ['analyse' => 5]]]), 'analysis.max_input_tokens.analyse'],
            'unknown max_output_tokens key' => [self::with(['analysis' => ['max_output_tokens' => ['x' => 5]]]), 'analysis.max_output_tokens.x'],
            'unknown redaction key' => [self::with(['redaction' => ['min_group_event_for_text' => 3]]), 'redaction.min_group_event_for_text'],
            'unknown state key' => [self::with(['state' => ['retenton' => 5]]), 'state.retenton'],
            'unknown state file key' => [self::with(['state' => ['file' => ['pth' => 'x']]]), 'state.file.pth'],
            'unknown state redis key' => [self::with(['state' => ['driver' => 'redis', 'redis' => ['hots' => 'x']]]), 'state.redis.hots'],
            'unknown state pdo key' => [self::with(['state' => ['driver' => 'pdo', 'pdo' => ['dsn' => 'sqlite::memory:', 'tabel' => 'x']]]), 'state.pdo.tabel'],
            'unknown notify key' => [self::with(['notify' => ['digets' => []]]), 'notify.digets'],
            'unknown notify digest key' => [self::with(['notify' => ['digest' => ['webhok' => 'x']]]), 'notify.digest.webhok'],
            'unknown deploy key' => [self::with(['deploy' => ['relase' => 'x']]), 'deploy.relase'],
            'unknown links key' => [self::with(['links' => ['grafanna' => []]]), 'links.grafanna'],
            'unknown links grafana key' => [self::with(['links' => ['grafana' => ['base_ur' => 'x']]]), 'links.grafana.base_ur'],
            'unknown repo key' => [self::with(['repo' => ['provider' => 'gitlab', 'web_url' => 'https://g', 'pth_map' => []]]), 'repo.pth_map'],
            'unknown dependency key' => [self::with(['dependencies' => ['core-api' => ['stream_tags' => ['app' => 'x'], 'stream_tag' => []]]]), 'dependencies.core-api.stream_tag'],
            'unknown watch rule key' => [self::with(['watch_rules' => ['q' => ['template' => '/a/', 'equal' => 'x']]]), 'watch_rules.q.equal'],
            'unknown mute key' => [self::with(['mute' => [['fingerprint' => 'abc', 'unti' => null]]]), 'mute.0.unti'],
            'negative daily budget' => [self::with(['analysis' => ['daily_budget_usd' => -1]]), 'analysis.daily_budget_usd'],
            'non-bool newest_first' => [self::with(['source' => ['driver' => 'datadog', 'datadog' => ['api_key' => 'a', 'app_key' => 'b', 'newest_first' => 'yes']]]), 'source.datadog.newest_first'],
            'bad level_mode' => [self::with(['source' => ['level_mode' => 'all-of-it']]), 'source.level_mode'],
            'non-string level_mode' => [self::with(['source' => ['level_mode' => true]]), 'source.level_mode'],
            'service_context too long' => [self::with(['service_context' => str_repeat('a', 1001)]), 'service_context'],
        ];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidCases')]
    public function testInvalidConfigNamesKeyAndLeaksNoSecret(array $config, string $key): void
    {
        try {
            WatchConfig::fromArray($config);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString($key, $e->getMessage());
            foreach (self::SECRETS as $secret) {
                self::assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    public function testSecretsInOffendingValuesAreNotEchoed(): void
    {
        $this->expectException(InvalidConfig::class);
        try {
            WatchConfig::fromArray(self::with([
                'source' => ['datadog' => ['api_key' => 'dd-secret', 'app_key' => 'app-secret'], 'driver' => 'datadog', 'event_cap' => 'app-secret'],
            ]));
        } catch (InvalidConfig $e) {
            self::assertStringNotContainsString('app-secret', $e->getMessage());
            self::assertStringNotContainsString('dd-secret', $e->getMessage());
            throw $e;
        }
    }

    public function testValidMatchersAndPastMuteDateAccepted(): void
    {
        $c = WatchConfig::fromArray(self::with([
            'watch_rules' => [
                'quota' => ['template' => '/QuotaTracking/'],
                'sql' => ['error_type' => '/PDOException/'],
                't' => ['field' => 'tenant', 'equals' => 'la'],
                'm' => ['field' => 'tenant', 'matches' => '/^l/'],
            ],
            'mute' => [
                ['fingerprint' => 'a1b2c3d4e5f6', 'until' => '2020-01-01'],
                ['template' => '/x/', 'until' => null],
            ],
            'redaction' => ['extra_patterns' => ['ORDER' => '/ORD-\d+/']],
            'dependencies' => ['core-api' => ['stream_tags' => ['app' => 'core-api']]],
        ]));

        self::assertCount(4, $c->watchRules);
        self::assertCount(2, $c->mute);
        self::assertArrayHasKey('core-api', $c->dependencies);
    }

    public function testDatadogDriverNeedsItsCredentials(): void
    {
        $c = WatchConfig::fromArray(self::with([
            'source' => ['driver' => 'datadog', 'datadog' => ['api_key' => 'a', 'app_key' => 'b']],
        ]));

        self::assertSame('datadog', $c->source['driver']);
    }

    public function testMinGroupEventsForTextDefaultsToThreeAndCanBeOverridden(): void
    {
        self::assertSame(3, WatchConfig::fromArray(self::valid())->minGroupEventsForText);
        self::assertSame(1, WatchConfig::fromArray(self::with(['redaction' => ['min_group_events_for_text' => 1]]))->minGroupEventsForText);
    }

    public function testInvalidConfigIsAnInvalidArgumentException(): void
    {
        self::assertInstanceOf(\InvalidArgumentException::class, new InvalidConfig('x'));
    }

    /** @return array<string, array{string, mixed}> */
    public static function hostileModelNames(): array
    {
        $out = [];
        foreach (\Watcher\Tests\Fakes\HostileValues::all() as $label => [$value]) {
            foreach (['triage', 'analyze', 'escalate'] as $step) {
                $out[$step . ' ' . $label] = [$step, $value];
            }
        }

        return $out;
    }

    /** A model name or Datadog site that is not a plain string must be refused as InvalidConfig, never a TypeError or a warning. */
    #[DataProvider('hostileModelNames')]
    public function testHostileModelNameIsInvalidConfig(string $step, mixed $value): void
    {
        $c = self::valid();
        $c['analysis']['models'][$step] = $value;

        $this->expectException(InvalidConfig::class);

        WatchConfig::fromArray($c);
    }

    /** @return array<string, array{mixed}> */
    public static function hostileSites(): array
    {
        return ['array' => [['x']], 'number' => [5], 'path' => ['evil.example/x'], 'userinfo' => ['evil.example@x'], 'space' => ['datadoghq eu']];
    }

    #[DataProvider('hostileSites')]
    public function testDatadogSiteIsAHostNameOrInvalidConfig(mixed $site): void
    {
        $c = self::valid();
        $c['source'] = ['driver' => 'datadog', 'datadog' => ['site' => $site, 'api_key' => 'a', 'app_key' => 'b']];

        $this->expectException(InvalidConfig::class);

        WatchConfig::fromArray($c);
    }
}
