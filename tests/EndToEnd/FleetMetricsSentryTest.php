<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Fleet\FleetRunner;
use Watcher\Operate\RunOptions;
use Watcher\Tests\Fakes\FrozenClock;

/** Spec 009 T053/T054: FleetRunner builds each service's own metrics and Sentry sources (own config, own tokens). */
final class FleetMetricsSentryTest extends TestCase
{
    /** @return array<string, mixed> */
    private function service(string $name, string $dir): array
    {
        return [
            'service' => $name,
            'enabled' => true,
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'http://127.0.0.1:1', 'allow_insecure' => true], 'stream_tags' => ['app' => $name]],
            'analysis' => ['api_key' => 'sk-secret-key', 'models' => ['triage' => 'm-t', 'analyze' => 'm-a', 'escalate' => 'm-e'], 'prices' => ['m-t' => [1, 5, 0.1, 1.25], 'm-a' => [3, 15, 0.3, 3.75], 'm-e' => [5, 25, 0.5, 6.25]]],
            'state' => ['driver' => 'file', 'file' => ['path' => $dir]],
            'notify' => ['digest' => ['webhook' => 'https://hooks.example/x']],
        ];
    }

    public function testServicesWithTheirOwnSectionsBuildAndRunWithoutLeakingATokenIntoTheResult(): void
    {
        $dir = sys_get_temp_dir() . '/fleet009-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $a = $this->service('api', $dir) + [
            'metrics' => ['source' => ['base_url' => 'https://127.0.0.1:1', 'allow_insecure' => true, 'auth' => ['type' => 'bearer', 'token' => 'CANARY-metrics-token-A']], 'queries' => ['up' => ['query' => 'up', 'aggregate' => 'max']]],
            'sentry' => ['organization' => 'acme', 'project' => 'api', 'auth_token' => 'CANARY-sentry-token-A'],
        ];
        $b = $this->service('bff', $dir);
        $runner = FleetRunner::fromConfig(['fleet' => 'platform', 'enabled' => true, 'services' => [$a, $b]], null, new FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00+00:00')));

        $result = $runner->run(null, RunOptions::default());

        self::assertStringNotContainsString('CANARY', print_r($result, true) . var_export($runner, true));
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }
}
