<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Watcher\Cli\Application;
use Watcher\Config\Factory;
use Watcher\Fleet\FleetConfig;
use Watcher\Fleet\FleetStatus;
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
                'prices' => ['m1' => [1, 5, 0, 0], 'm2' => [3, 15, 0, 0], 'm3' => [5, 25, 0, 0]],
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

    protected function tearDown(): void
    {
        @unlink($this->file);
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

    public function testCheckStillRejectsAnInvalidDisabledConfig(): void
    {
        $bad = self::config();
        $bad['schedule']['run_at'] = 'nonsense';
        file_put_contents($this->file, '<?php return ' . var_export($bad, true) . ';');

        [$code, $out] = $this->cli(['check']);

        self::assertNotSame(0, $code);
        self::assertStringContainsString('schedule.run_at', $out);
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
}
