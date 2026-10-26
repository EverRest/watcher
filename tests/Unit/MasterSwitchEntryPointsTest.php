<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Watcher\Config\Factory;
use Watcher\Config\WatchConfig;
use Watcher\Fleet\FleetRunner;
use Watcher\Hygiene\Hygiene;
use Watcher\Hygiene\HygieneOptions;
use Watcher\Mcp\HygieneSource;
use Watcher\Mcp\McpCommand;
use Watcher\Mcp\Tools\HygieneTool;
use Watcher\Operate\Doctor;
use Watcher\Operate\JsonSummary;
use Watcher\Operate\RunOptions;
use Watcher\RunOutcome;
use Watcher\Source\LokiSource;
use Watcher\State\FileStateStore;
use Watcher\Tests\EndToEnd\FleetHarness;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\RecordingStateStore;
use Watcher\Tests\Unit\Mcp\FakePipeline;
use Watcher\Tests\Unit\Mcp\McpHarness;
use Watcher\Watcher;

/**
 * When `enabled` is false every entry point returns disabled and makes zero source / state / notifier calls.
 * The Disabled summary carries `service` and `environment` when they look like names.
 */
final class MasterSwitchEntryPointsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(bool $enabled = false): array
    {
        return [
            'service' => 'orders-api',
            'environment' => 'prod',
            'enabled' => $enabled,
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.invalid']],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0, 0], 'm2' => [3, 15, 0, 0], 'm3' => [5, 25, 0, 0]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/nonexistent/watcher-killswitch-state']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x']],
        ];
    }

    public function testRunSummaryCarriesServiceAndEnvironment(): void
    {
        $result = Watcher::fromConfig(self::valid())->run();

        self::assertSame(RunOutcome::Disabled, $result->outcome);
        self::assertNotNull($result->summaryData);
        self::assertSame('orders-api', $result->summaryData->service);
        self::assertSame('prod', $result->summaryData->environment);
        $json = json_decode((new JsonSummary())->render($result->summaryData), true);
        self::assertSame('orders-api', $json['service']);
        self::assertSame('prod', $json['environment']);
        self::assertSame('disabled', $json['outcome']);
    }

    public function testDoctorRunTouchesNothing(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00Z'));
        $source = new FakeLogSource(['error' => 1]);
        $store = new RecordingStateStore(new InMemoryStateStore($clock));
        $notifier = new FakeNotifier();
        $doctor = new Doctor(WatchConfig::fromArray(self::valid()), $source, $store, null, $notifier, $clock);

        $report = $doctor->run(true);

        self::assertTrue($report->ok());
        self::assertSame([], $source->calls);
        self::assertSame([], $store->calls);
        self::assertSame([], $notifier->sent);
        self::assertSame(0, $notifier->attempts);
    }

    public function testDoctorFactoryBuildsNoRealCollaboratorsWhenDisabled(): void
    {
        $doctor = Factory::doctor(self::valid());
        $store = (new ReflectionProperty(Doctor::class, 'store'))->getValue($doctor);
        $source = (new ReflectionProperty(Doctor::class, 'source'))->getValue($doctor);

        self::assertNotInstanceOf(FileStateStore::class, $store);
        self::assertNotInstanceOf(LokiSource::class, $source);
        self::assertTrue($doctor->run()->ok());
    }

    public function testHygieneRunTouchesNothing(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00Z'));
        $source = new FakeLogSource(['error' => 1]);
        $store = new RecordingStateStore(new InMemoryStateStore($clock));
        $notifier = new FakeNotifier();
        $hygiene = new Hygiene(WatchConfig::fromArray(self::valid()), $source, $store, $notifier, $clock);

        $result = $hygiene->run(new HygieneOptions());

        self::assertTrue($result->disabled);
        self::assertSame(0, $result->exitCode());
        self::assertSame([], $source->calls);
        self::assertSame([], $store->calls);
        self::assertSame([], $notifier->sent);
    }

    public function testHygieneFromConfigBuildsNoRealCollaboratorsWhenDisabled(): void
    {
        $hygiene = Hygiene::fromConfig(self::valid());
        $source = (new ReflectionProperty(Hygiene::class, 'source'))->getValue($hygiene);

        self::assertNotInstanceOf(LokiSource::class, $source);
        self::assertTrue($hygiene->run(new HygieneOptions())->disabled);
    }

    public function testFleetRunnerTouchesNothing(): void
    {
        $h = new FleetHarness(fleetPatch: ['enabled' => false]);
        $rec = new RecordingStateStore($h->state);

        $r = $h->runner($rec)->run(null, RunOptions::default());

        self::assertSame(['disabled', 'disabled', 'disabled'], array_map(static fn ($s): string => $s->outcome, $r->services));
        self::assertSame([], $rec->calls);
        self::assertSame(0, $h->fleetNotifier->attempts);
        foreach ($h->sources as $s) {
            self::assertSame([], $s->calls);
        }
    }

    public function testFleetFromConfigBuildsNoFileStoreWhenDisabled(): void
    {
        $dir = sys_get_temp_dir() . '/fleet-off-' . bin2hex(random_bytes(4));
        $svc = self::valid();
        $svc['state'] = ['driver' => 'file', 'file' => ['path' => $dir]];
        $runner = FleetRunner::fromConfig([
            'fleet' => 'platform',
            'enabled' => false,
            'notify' => ['digest' => ['webhook' => 'https://hooks.invalid/f']],
            'services' => [$svc],
        ]);
        $state = (new ReflectionProperty(FleetRunner::class, 'state'))->getValue($runner);

        self::assertNotInstanceOf(FileStateStore::class, $state);
        self::assertSame(0, $runner->run(null, RunOptions::default())->exitCode());
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testHeartbeatStatusReadsNoStore(): void
    {
        $dir = sys_get_temp_dir() . '/watcher-hb-off-' . bin2hex(random_bytes(4));
        $cfg = self::valid();
        $cfg['state'] = ['driver' => 'file', 'file' => ['path' => $dir]];
        self::assertDirectoryDoesNotExist($dir);

        $status = Factory::heartbeatStatus($cfg, new FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00Z')));

        self::assertSame('disabled', $status->state);
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testMcpDoesNotBuildDryRunWhenDisabled(): void
    {
        $dir = sys_get_temp_dir() . '/mcp-off-' . bin2hex(random_bytes(4));
        mkdir($dir . '/state', 0700, true);
        $cfg = $dir . '/watcher.php';
        $values = self::valid() + [
            'state' => ['driver' => 'file', 'file' => ['path' => $dir . '/state']],
            'mcp' => ['allow_dry_run' => true, 'groups' => ['inspect']],
        ];
        file_put_contents($cfg, '<?php return ' . var_export($values, true) . ';');
        $dryBuilt = 0;
        $in = McpHarness::mem();
        $out = McpHarness::mem();
        $err = McpHarness::mem();
        fwrite($in, '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}' . "\n");
        fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JSON_THROW_ON_ERROR) . "\n");
        fwrite($in, json_encode(McpHarness::call('watcher_status', [], 2), JSON_THROW_ON_ERROR) . "\n");
        fwrite($in, json_encode(McpHarness::call('watcher_hygiene_report', [], 3), JSON_THROW_ON_ERROR) . "\n");
        rewind($in);

        $code = McpCommand::main(
            ['watcher', 'mcp', '--config=' . $cfg],
            $in,
            $out,
            $err,
            new FrozenClock(new DateTimeImmutable('2026-10-25T09:00:00Z')),
            null,
            static function () use (&$dryBuilt): FakePipeline {
                $dryBuilt++;

                return new FakePipeline();
            },
        );
        rewind($out);
        $lines = array_map(
            static fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR),
            explode("\n", rtrim((string) stream_get_contents($out), "\n")),
        );

        self::assertSame(0, $code);
        self::assertSame(0, $dryBuilt, 'dry-run adapter must not be built when the master switch is off');
        self::assertNotContains('watcher_dry_run', array_column($lines[1]['result']['tools'], 'name'));
        self::assertSame('disabled', McpHarness::envelope($lines[2])['data']['state']);
        self::assertSame(['available' => false, 'reason' => 'disabled'], McpHarness::envelope($lines[3])['data']);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testHygieneToolRespectsMasterSwitch(): void
    {
        $source = new class () implements HygieneSource {
            public function latest(): ?array
            {
                throw new \LogicException('hygiene source must not be read when disabled');
            }
        };

        self::assertSame(['available' => false, 'reason' => 'disabled'], (new HygieneTool($source, false))->call([]));
    }
}
