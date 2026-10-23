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
                'loki' => ['url' => 'http://loki', 'auth' => ['type' => 'bearer', 'token' => 'tok-secret']],
            ],
            'analysis' => [
                'api_key' => 'sk-secret-key',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
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

    public function testMinimalConfigGetsDefaults(): void
    {
        $c = WatchConfig::fromArray(self::valid());

        self::assertSame('svc', $c->service);
        self::assertSame('06:00', $c->schedule['run_at']);
        self::assertSame('UTC', $c->schedule['timezone']);
        self::assertSame('PT24H', $c->schedule['window']);
        self::assertSame(900, $c->schedule['lock_ttl']);
        self::assertSame(Level::Error, $c->selection['min_level']);
        self::assertSame(3.0, $c->selection['growth_factor']);
        self::assertSame(50, $c->selection['top_n']);
        self::assertSame(10, $c->selection['top_m']);
        self::assertSame(Level::Warning, $c->source['read_min_level']);
        self::assertSame(3, $c->source['sample_size']);
        self::assertSame(200000, $c->source['event_cap']);
        self::assertSame(20000, $c->source['anomaly_cap']);
        self::assertSame(0.6, $c->analysis['confidence_threshold']);
        self::assertSame(3, $c->analysis['escalation_cap']);
        self::assertSame(2592000, $c->state['retention']);
        self::assertSame(5000, $c->dependencyReadCap);
        self::assertSame(['svc.cluster.local'], $c->dependencyDomains);
        self::assertSame([], $c->watchRules);
        self::assertSame([], $c->mute);
        self::assertSame($c->notify['digest'], $c->notify['notice']);
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
        self::assertSame('https://hooks.example/other', $c->notify['notice']['webhook']);
        self::assertSame('short', $c->serviceContext);
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
            'missing state driver' => [$unset(['state', 'driver']), 'state.driver'],
            'missing file path' => [$unset(['state', 'file', 'path']), 'state.file.path'],
            'pdo without dsn' => [self::with(['state' => ['driver' => 'pdo']]), 'state.pdo.dsn'],
            'datadog without keys' => [self::with(['source' => ['driver' => 'datadog']]), 'source.datadog.api_key'],
            'missing webhook' => [$unset(['notify', 'digest', 'webhook']), 'notify.digest.webhook'],
            'unknown source driver' => [self::with(['source' => ['driver' => 'nope']]), 'source.driver'],
            'unknown top-level key' => [self::with(['analisys' => []]), 'analisys'],
            'growth_factor = 1' => [self::with(['selection' => ['growth_factor' => 1]]), 'selection.growth_factor'],
            'confidence 0' => [self::with(['analysis' => ['confidence_threshold' => 0]]), 'analysis.confidence_threshold'],
            'confidence 1' => [self::with(['analysis' => ['confidence_threshold' => 1]]), 'analysis.confidence_threshold'],
            'top_m > top_n' => [self::with(['selection' => ['top_n' => 5, 'top_m' => 6]]), 'selection.top_m'],
            'zero top_n' => [self::with(['selection' => ['top_n' => 0]]), 'selection.top_n'],
            'negative retention' => [self::with(['state' => ['retention' => -1]]), 'state.retention'],
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
            'min_group_events_for_text zero' => [self::with(['redaction' => ['min_group_events_for_text' => 0]]), 'redaction.min_group_events_for_text'],
            'min_group_events_for_text negative' => [self::with(['redaction' => ['min_group_events_for_text' => -2]]), 'redaction.min_group_events_for_text'],
            'min_group_events_for_text string' => [self::with(['redaction' => ['min_group_events_for_text' => '3']]), 'redaction.min_group_events_for_text'],
            'min_group_events_for_text float' => [self::with(['redaction' => ['min_group_events_for_text' => 2.5]]), 'redaction.min_group_events_for_text'],
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
}
