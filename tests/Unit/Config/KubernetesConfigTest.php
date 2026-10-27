<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

/** Spec 008, FR-004: invalid or missing settings fail before any call, naming the setting and never a value. */
final class KubernetesConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example'], 'stream_tags' => ['app' => 'svc']],
            'analysis' => [
                'api_keys' => ['gemini' => 'k'],
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],

            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
            'kubernetes' => ['namespaces' => ['shop']],
            'events' => ['driver' => 'kubernetes'],
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

    public function testDefaults(): void
    {
        $k = WatchConfig::fromArray(self::valid())->kubernetes;

        self::assertNotNull($k);
        self::assertTrue($k->enabled);
        self::assertTrue($k->inCluster);
        self::assertSame(['shop'], $k->namespaces);
        self::assertNull($k->labelSelector);
        self::assertSame(['Pod', 'Deployment', 'ReplicaSet', 'StatefulSet', 'DaemonSet', 'Job', 'HorizontalPodAutoscaler'], $k->involvedKinds);
        self::assertContains('OOMKilled', $k->criticalReasons);
        self::assertContains('BackOff', $k->criticalReasons);
        self::assertContains('FailedScheduling', $k->criticalReasons);
        self::assertSame(3, $k->minRepeatForProbeFailures);
        self::assertSame(5000, $k->eventCap);
        self::assertSame(120, $k->tolerance);
        self::assertSame(3600, $k->eventTtl);
        self::assertFalse($k->exporter);
        self::assertTrue($k->events);
        self::assertFalse(WatchConfig::fromArray(self::valid())->on('kubernetes') === false);
    }

    public function testNothingChangesWithoutEventsOrKubernetes(): void
    {
        $plain = self::valid();
        unset($plain['kubernetes'], $plain['events']);
        $c = WatchConfig::fromArray($plain);

        self::assertNull($c->kubernetes);
        self::assertTrue($c->on('kubernetes'));
    }

    public function testFlagAloneNeedsNoNamespaces(): void
    {
        $plain = self::valid();
        unset($plain['events']);
        $plain['kubernetes'] = ['enabled' => false];
        $c = WatchConfig::fromArray($plain);

        self::assertFalse($c->on('kubernetes'));
    }

    public function testSectionWithoutUseStillValidatedWhenItHasSettings(): void
    {
        $plain = self::valid();
        unset($plain['events']);
        $plain['kubernetes'] = ['label_selector' => 'app in (api'];

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('kubernetes.');
        WatchConfig::fromArray($plain);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalid(): array
    {
        return [
            'no namespaces' => [['kubernetes' => ['namespaces' => null]], 'kubernetes.namespaces'],
            'namespaces not a list' => [['kubernetes' => ['namespaces' => 'shop']], 'kubernetes.namespaces'],
            'namespace not a dns label' => [['kubernetes' => ['namespaces' => ['Shop_1']]], 'kubernetes.namespaces'],
            'wildcard namespace' => [['kubernetes' => ['namespaces' => ['*']]], 'kubernetes.namespaces'],
            'bad selector' => [['kubernetes' => ['label_selector' => 'app in (api']], 'kubernetes.label_selector'],
            'empty selector' => [['kubernetes' => ['label_selector' => '']], 'kubernetes.label_selector'],
            'unknown key' => [['kubernetes' => ['all_namespaces' => true]], 'kubernetes.all_namespaces'],
            'server without token' => [['kubernetes' => ['api' => ['server' => 'https://k8s.example:6443']]], 'kubernetes.api.token'],
            'plain http server' => [['kubernetes' => ['api' => ['server' => 'http://k8s.example', 'token' => 't']]], 'kubernetes.api.server'],
            'server with credentials' => [['kubernetes' => ['api' => ['server' => 'https://u:p@k8s.example', 'token' => 't']]], 'kubernetes.api.server'],
            'metadata address' => [['kubernetes' => ['api' => ['server' => 'https://169.254.169.254', 'token' => 't']]], 'kubernetes.api.server'],
            'in cluster and server' => [['kubernetes' => ['api' => ['in_cluster' => true, 'server' => 'https://k8s.example', 'token' => 't']]], 'kubernetes.api'],
            'unknown api key' => [['kubernetes' => ['api' => ['insecure' => true]]], 'kubernetes.api.insecure'],
            'kinds not a list' => [['kubernetes' => ['involved_kinds' => 'Pod']], 'kubernetes.involved_kinds'],
            'bad kind' => [['kubernetes' => ['involved_kinds' => ['pod; drop']]], 'kubernetes.involved_kinds'],
            'bad reason' => [['kubernetes' => ['critical_reasons' => ['OOM Killed']]], 'kubernetes.critical_reasons'],
            'zero threshold' => [['kubernetes' => ['min_repeat_for_probe_failures' => 0]], 'kubernetes.min_repeat_for_probe_failures'],
            'cap' => [['kubernetes' => ['event_cap' => 0]], 'kubernetes.event_cap'],
            'tolerance' => [['kubernetes' => ['tolerance' => -1]], 'kubernetes.tolerance'],
            'ttl' => [['kubernetes' => ['event_ttl' => 0]], 'kubernetes.event_ttl'],
            'ledger ttl shorter than the trigger interval' => [['kubernetes' => ['ledger_ttl' => 600]], 'kubernetes.ledger_ttl'],
            'exporter' => [['kubernetes' => ['exporter' => 'yes']], 'kubernetes.exporter'],
            'allow' => [['kubernetes' => ['allow' => ['registries' => 'ghcr.io']]], 'kubernetes.allow.registries'],
            'flag' => [['kubernetes' => ['enabled' => 'no']], 'kubernetes.enabled'],
            'events driver' => [['events' => ['driver' => 'loki']], 'events.driver'],
            'events unknown key' => [['events' => ['driver' => 'kubernetes', 'x' => 1]], 'events.x'],
        ];
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('invalid')]
    public function testInvalidSettingsNameTheKey(array $patch, string $path): void
    {
        try {
            WatchConfig::fromArray(self::with($patch));
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString($path, $e->getMessage());
        }
    }

    public function testEventsNeedTheSection(): void
    {
        $c = self::valid();
        unset($c['kubernetes']);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('events needs the kubernetes section');
        WatchConfig::fromArray($c);
    }

    public function testAnEmptyNamespaceListIsRejectedThereIsNoAllNamespacesDefault(): void
    {
        $c = self::valid();
        $c['kubernetes'] = ['namespaces' => []];

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('kubernetes.namespaces');
        WatchConfig::fromArray($c);
    }

    public function testNamespacesMissingEntirelyIsAConfigErrorBeforeAnyCall(): void
    {
        $c = self::valid();
        $c['kubernetes'] = ['label_selector' => 'app=api'];

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('kubernetes.namespaces');
        WatchConfig::fromArray($c);
    }

    /** @return array<string, array{string, bool, bool}> server, allow_insecure, accepted: develop's SecureUrl rule */
    public static function servers(): array
    {
        return [
            'https public' => ['https://k8s.example:6443', false, true],
            'http public never' => ['http://k8s.example', true, false],
            'http private without the flag' => ['http://10.0.0.5:8080', false, false],
            'http private with the flag' => ['http://10.0.0.5:8080', true, true],
            'http cluster-local with the flag' => ['http://kube-api.default.svc:8080', true, true],
            'http loopback without the flag' => ['http://127.0.0.1:8001', false, false],
            'http loopback with the flag (kubectl proxy)' => ['http://127.0.0.1:8001', true, true],
            'https loopback without the flag' => ['https://localhost:6443', false, false],
            'https loopback with the flag' => ['https://localhost:6443', true, true],
            'metadata address even with the flag' => ['https://169.254.169.254', true, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('servers')]
    public function testTheApiServerFollowsTheSecureUrlRule(string $server, bool $allowInsecure, bool $accepted): void
    {
        $api = ['server' => $server, 'token' => 't'] + ($allowInsecure ? ['allow_insecure' => true] : []);
        if (!$accepted) {
            $this->expectException(InvalidConfig::class);
            $this->expectExceptionMessage('kubernetes.api.server');
        }
        $c = WatchConfig::fromArray(self::with(['kubernetes' => ['api' => $api]]));

        self::assertNotNull($c->kubernetes);
    }

    public function testAllowInsecureMustBeABoolean(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('kubernetes.api.allow_insecure');
        WatchConfig::fromArray(self::with(['kubernetes' => ['api' => ['server' => 'http://10.0.0.5', 'token' => 't', 'allow_insecure' => 'yes']]]));
    }

    public function testErrorsNeverContainTheToken(): void
    {
        try {
            WatchConfig::fromArray(self::with(['kubernetes' => ['api' => ['server' => 'http://k8s.example', 'token' => 'SECRET-TOKEN-VALUE']]]));
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringNotContainsString('SECRET-TOKEN-VALUE', $e->getMessage());
        }
    }

    public function testExplicitCredentialsAndOverrides(): void
    {
        $ca = sys_get_temp_dir() . '/kube-cfg-ca-' . bin2hex(random_bytes(8)) . '.crt';
        file_put_contents($ca, "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");
        $k = WatchConfig::fromArray(self::with(['kubernetes' => [
            'api' => ['server' => 'https://k8s.example:6443/', 'token' => 'tok', 'ca_file' => $ca],
            'namespaces' => ['shop', 'billing'],
            'label_selector' => 'app in (api,bff),!canary',
            'involved_kinds' => ['Pod', 'Node'],
            'critical_reasons' => ['OOMKilled', 'FailedGetResourceMetric'],
            'min_repeat_for_probe_failures' => 5,
            'event_cap' => 100,
            'tolerance' => 0,
            'event_ttl' => 7200,
            'ledger_ttl' => 90000,
            'allow' => ['registries' => ['ghcr.io'], 'images' => ['org/api']],
            'exporter' => true,
        ]]))->kubernetes;

        self::assertNotNull($k);
        self::assertFalse($k->inCluster);
        self::assertSame('https://k8s.example:6443', $k->server);
        self::assertSame('tok', $k->token());
        self::assertSame($ca, $k->caFile);
        unlink($ca);
        self::assertSame(['shop', 'billing'], $k->namespaces);
        self::assertSame('app in (api,bff),!canary', $k->labelSelector?->text());
        self::assertSame(['Pod', 'Node'], $k->involvedKinds);
        self::assertSame(['OOMKilled', 'FailedGetResourceMetric'], $k->criticalReasons);
        self::assertSame(5, $k->minRepeatForProbeFailures);
        self::assertSame(0, $k->tolerance);
        self::assertSame(90000, $k->ledgerTtl);
        self::assertSame(['ghcr.io'], $k->allowRegistries);
        self::assertSame(['org/api'], $k->allowImages);
        self::assertTrue($k->exporter);
    }

    public function testKubernetesCanBeTheOnlySourceWithoutStreamTags(): void
    {
        $c = self::with(['source' => ['driver' => 'kubernetes']]);
        unset($c['events'], $c['source']['loki'], $c['source']['stream_tags']);
        $cfg = WatchConfig::fromArray($c);

        self::assertSame('kubernetes', $cfg->source['driver']);
        self::assertSame([], $cfg->source['stream_tags']);
        self::assertNotNull($cfg->kubernetes);
        self::assertTrue($cfg->kubernetes->asSource);
    }

    public function testKubernetesAsSourceNeedsTheSectionAndTheFlag(): void
    {
        $c = self::valid();
        $c['source'] = ['driver' => 'kubernetes'];
        unset($c['events'], $c['kubernetes']);
        try {
            WatchConfig::fromArray($c);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertStringContainsString('source.driver kubernetes needs the kubernetes section', $e->getMessage());
        }

        $c['kubernetes'] = ['namespaces' => ['shop'], 'enabled' => false];
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('kubernetes.enabled');
        WatchConfig::fromArray($c);
    }

    public function testDependenciesNeedALogSourceOfTheirOwn(): void
    {
        $c = self::with(['source' => ['driver' => 'kubernetes'], 'dependencies' => ['payments' => ['stream_tags' => ['app' => 'payments']]]]);
        unset($c['events'], $c['source']['loki'], $c['source']['stream_tags']);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('dependencies');
        WatchConfig::fromArray($c);
    }

    public function testTheFlagIsListed(): void
    {
        self::assertContains('kubernetes', WatchConfig::FLAGS);
    }
}
