<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Fleet\FleetRunner;
use Watcher\Operate\RunOptions;
use Watcher\Tests\Fakes\FrozenClock;

/** Spec 013 T016: FleetRunner::fromConfig gives each service its own sinks; the events carry that service's names. */
final class FleetObserveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fleetobs-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** @return array<string, mixed> */
    private function service(string $name, string $env): array
    {
        return [
            'service' => $name,
            'environment' => $env,
            'enabled' => true,
            'schedule' => ['run_at' => '06:00'],
            // Nothing listens on port 1: the read fails at once, which is a failed run, and a failed run still emits its run event.
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'http://127.0.0.1:1', 'allow_insecure' => true], 'stream_tags' => ['app' => $name]],
            'analysis' => ['api_key' => 'sk-secret-key', 'models' => ['triage' => 'm-t', 'analyze' => 'm-a', 'escalate' => 'm-e'], 'prices' => ['m-t' => [1, 5, 0.1, 1.25], 'm-a' => [3, 15, 0.3, 3.75], 'm-e' => [5, 25, 0.5, 6.25]]],
            'state' => ['driver' => 'file', 'file' => ['path' => $this->dir . '/state']],
            'notify' => ['digest' => ['driver' => 'none']],
        ];
    }

    public function testAServiceWritesItsOwnMetricsFileNamedAfterItsServiceAndEnvironment(): void
    {
        mkdir($this->dir . '/state', 0700);
        $runner = FleetRunner::fromConfig([
            'fleet' => 'platform',
            'enabled' => true,
            'services' => [$this->service('api', 'prod')],
            'defaults' => ['observe' => ['prometheus' => ['enabled' => true, 'dir' => $this->dir]]],
        ], null, new FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00+00:00')));

        $runner->run(null, RunOptions::default());

        self::assertSame(['watcher_api_prod.prom'], array_map('basename', glob($this->dir . '/*.prom') ?: []));
        $api = (string) file_get_contents($this->dir . '/watcher_api_prod.prom');
        self::assertStringContainsString('service="api",environment="prod"', $api);
        self::assertStringContainsString('watcher_last_run_outcome{service="api",environment="prod",outcome="failed"} 1', $api);
    }
}
