<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Cli\Application;
use Watcher\Config\Factory;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\Operate\RunOptions;
use Watcher\RunOutcome;
use Watcher\Watcher;

/** The kill switch works on a broken or half-filled config: `enabled` is read from the raw array before anything is validated or built. */
final class MasterSwitchTest extends TestCase
{
    private const CANARY = 'CANARY-SECRET-9f3a';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/watcher-switch-' . bin2hex(random_bytes(4));
    }

    /** @return array<string, mixed> no source.loki.url, no models: invalid whenever it is validated */
    private function halfFilled(mixed $enabled): array
    {
        $c = [
            'service' => 'svc',
            'source' => ['driver' => 'loki', 'loki' => ['auth' => ['type' => 'bearer', 'token' => self::CANARY]]],
            'analysis' => ['api_keys' => ['gemini' => self::CANARY]],
            'state' => ['driver' => 'file', 'file' => ['path' => $this->dir]],
        ];
        if ($enabled !== 'missing') {
            $c['enabled'] = $enabled;
        }

        return $c;
    }

    /** @return iterable<string, array{mixed}> */
    public static function offForms(): iterable
    {
        foreach ([false, null, 'missing', '0', 'false', 'off', '', 0, 1, 'yes', 'true', 'banana', [], 'TRUE'] as $v) {
            yield var_export($v, true) => [$v];
        }
    }

    #[DataProvider('offForms')]
    public function testOnlyBooleanTrueTurnsTheSwitchOn(mixed $form): void
    {
        self::assertTrue(WatchConfig::masterSwitchOff($this->halfFilled($form)));
    }

    public function testBooleanTrueIsOn(): void
    {
        self::assertFalse(WatchConfig::masterSwitchOff($this->halfFilled(true)));
    }

    #[DataProvider('offForms')]
    public function testHalfFilledConfigWithSwitchOffIsDisabledAndTouchesNothing(mixed $form): void
    {
        $result = Watcher::fromConfig($this->halfFilled($form))->run();

        self::assertSame(RunOutcome::Disabled, $result->outcome);
        self::assertSame(0, $result->exitCode());
        self::assertDirectoryDoesNotExist($this->dir);
        self::assertStringNotContainsString(self::CANARY, $result->summary());
    }

    public function testDryRunAndEstimateWithSwitchOffAreDisabledToo(): void
    {
        $out = '';
        $r = Watcher::fromConfig($this->halfFilled(false), null, null, new RunOptions(true, true, false), static function (string $s) use (&$out): void {
            $out .= $s;
        })->run();

        self::assertSame(RunOutcome::Disabled, $r->outcome);
        self::assertSame('', $out);
        self::assertDirectoryDoesNotExist($this->dir);
    }

    public function testHalfFilledConfigWithSwitchOnIsStillInvalid(): void
    {
        $this->expectException(InvalidConfig::class);
        Watcher::fromConfig($this->halfFilled(true));
    }

    public function testFullValidationStillRunsOnTheSwitchOffConfig(): void
    {
        $this->expectException(InvalidConfig::class);
        WatchConfig::fromArray($this->halfFilled(false));
    }

    public function testCliRunAndCheckExitZeroWhenTheConfigIsBrokenAndSwitchedOff(): void
    {
        $file = sys_get_temp_dir() . '/watcher-switch-cfg-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, '<?php return ' . var_export($this->halfFilled(false), true) . ';');
        try {
            $run = $this->cli(['run'], $file);
            $check = $this->cli(['check'], $file);
        } finally {
            unlink($file);
        }

        self::assertSame(0, $run[0]);
        self::assertStringContainsString(Application::DISABLED_HINT, $run[2]);
        self::assertStringNotContainsString(self::CANARY, $run[1] . $run[2]);
        self::assertStringNotContainsString('invalid config', $run[2]);
        self::assertSame(0, $check[0]);
        self::assertStringContainsString('disabled', $check[1]);
        self::assertStringNotContainsString('invalid', $check[1]);
        self::assertStringNotContainsString(self::CANARY, $check[1] . $check[2]);
        self::assertDirectoryDoesNotExist($this->dir);
    }

    public function testCliRunWithSwitchOnAndHalfFilledConfigExitsTwo(): void
    {
        $file = sys_get_temp_dir() . '/watcher-switch-cfg-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, '<?php return ' . var_export($this->halfFilled(true), true) . ';');
        try {
            [$code, , $err] = $this->cli(['run'], $file);
        } finally {
            unlink($file);
        }

        self::assertSame(2, $code);
        self::assertStringContainsString('invalid config', $err);
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function cli(array $args, string $file): array
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
        );
        $code = $app->run(['watcher', ...$args, '--config=' . $file]);
        rewind($err);
        rewind($out);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
