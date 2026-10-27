<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Watcher\Cli\Application;
use Watcher\Config\Factory;
use Watcher\Fleet\FleetCheck;
use Watcher\Fleet\FleetConfig;
use Watcher\Fleet\FleetRunner;
use Watcher\Fleet\FleetStatus;
use Watcher\Hygiene\Hygiene;
use Watcher\Hygiene\HygieneOptions;
use Watcher\Operate\RunOptions;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\RecordingStateStore;
use Watcher\Watcher;

/** Spec 012: with `enabled` false every command still works, reports `disabled`, and reaches nothing external. */
final class DisabledCommandsTest extends TestCase
{
    private string $file;

    /** @return array<string, mixed> */
    private static function config(bool $enabled = false): array
    {
        return [
            'service' => 'svc',
            'enabled' => $enabled,
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.invalid']],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],

            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/nonexistent/watcher']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x']],
        ];
    }

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/watcher-off-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->file, '<?php return ' . var_export(self::config(), true) . ';');
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function cli(array $args): array
    {
        $err = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($err);
        self::assertIsResource($out);
        $app = new Application(
            static fn (array $config, RunOptions $options): callable => Watcher::fromConfig($config, null, null, $options)->run(...),
            $err,
            $out,
            static fn (array $config, bool $postTest): \Watcher\Operate\CheckReport => Factory::doctor($config)->run($postTest),
            static fn (array $config): \Watcher\Operate\HeartbeatStatus => Factory::heartbeatStatus($config),
        );
        $code = $app->run(['watcher', ...$args, '--config=' . $this->file]);
        rewind($err);
        rewind($out);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testRunPrintsTheHintOnStderrAndExitsZero(): void
    {
        [$code, $out, $err] = $this->cli(['run']);

        self::assertSame(0, $code);
        self::assertStringContainsString("watcher: disabled (set enabled=true / WATCHER_ENABLED=1)\n", $err);
        self::assertStringContainsString('outcome=disabled', $out);
    }

    public function testRunJsonReportsOutcomeDisabled(): void
    {
        [$code, $out, $err] = $this->cli(['run', '--json']);

        self::assertSame(0, $code);
        $json = json_decode(trim($out), true);
        self::assertSame('disabled', $json['outcome']);
        self::assertStringContainsString('WATCHER_ENABLED=1', $err);
    }

    public function testStatusReportsDisabledWithoutReadingTheStore(): void
    {
        [$code, $out] = $this->cli(['status']);

        self::assertSame(0, $code);
        self::assertStringContainsString('state:          disabled', $out);
        [, $json] = $this->cli(['status', '--json']);
        self::assertSame('disabled', json_decode(trim(substr($json, (int) strrpos(trim($json), "\n"))), true)['state']);
    }

    public function testCheckValidatesTheConfigButCallsNothing(): void
    {
        [$code, $out] = $this->cli(['check']);

        self::assertSame(0, $code);
        self::assertStringContainsString('disabled', $out);
        self::assertStringNotContainsString('FAIL', $out);
        self::assertStringContainsString('result: ok', $out);
    }

    public function testCheckOfAnInvalidDisabledConfigIsDisabledAndValidatesNothing(): void
    {
        $bad = self::config();
        $bad['schedule']['run_at'] = 'nonsense';
        file_put_contents($this->file, '<?php return ' . var_export($bad, true) . ';');

        [$code, $out] = $this->cli(['check']);

        self::assertSame(0, $code);
        self::assertStringContainsString('disabled', $out);
        self::assertStringNotContainsString('schedule.run_at', $out);
    }

    public function testHeartbeatOffAlsoMakesStatusDisabled(): void
    {
        $c = self::config(true);
        $c['heartbeat'] = ['enabled' => false];

        self::assertSame('disabled', Factory::heartbeatStatus($c)->state);
    }

    public function testFleetStatusReadsNothingForDisabledServices(): void
    {
        $svc = static fn (string $n, bool $on): array => ['service' => $n, 'enabled' => $on] + array_diff_key(self::config(), ['service' => 1, 'enabled' => 1]);
        $fleet = FleetConfig::fromArray(['fleet' => 'f', 'notify' => ['digest' => ['webhook' => 'https://hooks.invalid/f']], 'services' => [$svc('a', false), $svc('b', false)]]);
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-08T06:00:00+00:00'));
        $store = new RecordingStateStore(new InMemoryStateStore($clock));

        $status = FleetStatus::forState($fleet, $store, $clock);

        foreach ($store->calls as [, $key]) {
            self::assertStringContainsString('fleet', $key, 'only the fleet\'s own record may be read');
        }
        self::assertSame(['disabled', 'disabled'], array_values(array_map(static fn ($h): string => $h->state, $status->services)));
    }

    /** A fleet file, switched off, whose second entry cannot validate. Nothing may read the entry. */
    private function switchedOffFleetFile(): string
    {
        $dir = sys_get_temp_dir() . '/watcher-off-state-' . bin2hex(random_bytes(4));
        $svc = static fn (string $n): array => ['service' => $n] + array_diff_key(self::config(true), ['service' => 1]) + ['state' => ['driver' => 'file', 'file' => ['path' => $dir]]];
        $file = sys_get_temp_dir() . '/watcher-off-fleet-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, '<?php return ' . var_export(['fleet' => 'platform', 'enabled' => false, 'services' => [$svc('api'), ['service' => 'bad-one']]], true) . ';');
        $this->fleetFiles[] = $file;

        return $file;
    }

    /** @var list<string> */
    private array $fleetFiles = [];

    protected function tearDown(): void
    {
        @unlink($this->file);
        foreach ($this->fleetFiles as $file) {
            @unlink($file);
        }
    }

    public function testFleetRunOfASwitchedOffFleetIsDisabledAndTouchesNoState(): void
    {
        $file = $this->switchedOffFleetFile();
        $dir = self::stateDirOf($file);

        $result = FleetRunner::fromConfig($file)->run(null, RunOptions::default());

        self::assertSame(['api', 'bad-one'], array_map(static fn ($s): string => $s->service, $result->services));
        self::assertSame(['disabled', 'disabled'], array_map(static fn ($s): string => $s->outcome, $result->services));
        self::assertSame(0, $result->exitCode());
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testFleetStatusOfASwitchedOffFleetReadsNoStore(): void
    {
        $file = $this->switchedOffFleetFile();
        $dir = self::stateDirOf($file);

        $status = FleetStatus::fromConfig($file);

        self::assertSame(0, $status->exitCode());
        self::assertSame(['api', 'bad-one'], array_keys($status->services));
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testFleetCheckOfASwitchedOffFleetCallsNothing(): void
    {
        $file = $this->switchedOffFleetFile();
        $dir = self::stateDirOf($file);

        $report = FleetCheck::fromConfig($file)->run(false);

        self::assertSame(0, $report->exitCode());
        self::assertStringContainsString('disabled', $report->text());
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testRunOfASwitchedOffFleetFileIsDisabledWithoutValidatingItsServices(): void
    {
        $file = $this->switchedOffFleetFile();
        $this->file = $file;

        [$code, $out] = $this->cli(['run']);

        self::assertSame(0, $code);
        self::assertStringContainsString('outcome=disabled', $out);
    }

    public function testCheckOfASwitchedOffFleetFileOpensNoServiceAndBuildsNothing(): void
    {
        $file = $this->switchedOffFleetFile();
        $dir = self::stateDirOf($file);
        $this->file = $file;

        [$code, $out] = $this->cli(['check']);

        self::assertSame(0, $code);
        self::assertStringContainsString('disabled', $out);
        self::assertStringNotContainsString('bad-one', $out);
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testStatusOfASwitchedOffFleetFileReadsNoStore(): void
    {
        $file = $this->switchedOffFleetFile();
        $dir = self::stateDirOf($file);
        $this->file = $file;

        $this->cli(['status']);

        self::assertDirectoryDoesNotExist($dir);
    }

    public function testHygieneOfASwitchedOffFleetFileReadsNoStore(): void
    {
        $file = $this->switchedOffFleetFile();
        $dir = self::stateDirOf($file);

        // Hygiene reads the master switch before validation: the fleet keys are not validated and nothing is built.
        $result = Hygiene::fromConfig(require $file)->run(HygieneOptions::fromInput(null, null, null, false));

        self::assertTrue($result->disabled);
        self::assertDirectoryDoesNotExist($dir);
    }

    private static function stateDirOf(string $file): string
    {
        $config = require $file;

        return $config['services'][0]['state']['file']['path'];
    }
}
