<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Watcher\Cli\Application;
use Watcher\Config\InvalidConfig;

final class CliStateGetTest extends TestCase
{
    private string $cfg;

    protected function setUp(): void
    {
        $this->cfg = sys_get_temp_dir() . '/watcher-cli-sg-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($this->cfg, '<?php return ["service" => "svc"];');
    }

    protected function tearDown(): void
    {
        @unlink($this->cfg);
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function exec(array $args, ?\Closure $factory): array
    {
        $err = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($err);
        self::assertIsResource($out);
        $code = (new Application(static fn (): callable => throw new \LogicException(), $err, $out, stateGetFactory: $factory))->run(['watcher', ...$args]);
        rewind($err);
        rewind($out);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testPrintsTheValueAndPassesTheKeyAndConfig(): void
    {
        $seen = null;
        [$code, $out] = $this->exec(['state:get', 'heartbeat', '--config=' . $this->cfg], static function (array $config, string $spec) use (&$seen): string {
            $seen = [$config['service'], $spec];

            return '{"outcome":"quiet"}';
        });

        self::assertSame(0, $code);
        self::assertSame("{\"outcome\":\"quiet\"}\n", $out);
        self::assertSame(['svc', 'heartbeat'], $seen);
    }

    public function testMissingEntryExitsOne(): void
    {
        [$code, $out, $err] = $this->exec(['state:get', 'heartbeat', '--config=' . $this->cfg], static fn (): ?string => null);

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('no entry', $err);
    }

    public function testARefusedKeyExitsTwoWithTheReason(): void
    {
        [$code, $out, $err] = $this->exec(['state:get', 'watcher:*', '--config=' . $this->cfg], static fn () => throw new InvalidArgumentException('state:get: not a logical key'));

        self::assertSame(2, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('not a logical key', $err);
    }

    public function testInvalidConfigExitsTwo(): void
    {
        [$code, , $err] = $this->exec(['state:get', 'heartbeat', '--config=' . $this->cfg], static fn () => throw new InvalidConfig('state.driver is required'));

        self::assertSame(2, $code);
        self::assertStringContainsString('invalid config: state.driver is required', $err);
    }

    public function testUsageWithoutAKeyOrWithTwoKeysOrAnUnknownFlag(): void
    {
        foreach ([['state:get', '--config=' . $this->cfg], ['state:get', 'heartbeat', 'run:x', '--config=' . $this->cfg], ['state:get', 'heartbeat', '--json', '--config=' . $this->cfg], ['state:get', 'heartbeat']] as $args) {
            [$code, , $err] = $this->exec($args, static fn (): string => 'x');
            self::assertSame(2, $code, implode(' ', $args));
            self::assertStringContainsString('usage:', $err);
        }
    }

    public function testAnUnavailableStoreExitsOneWithTheClassNameOnly(): void
    {
        [$code, $out, $err] = $this->exec(['state:get', 'heartbeat', '--config=' . $this->cfg], static fn (): string => throw new \RuntimeException('connect to 10.0.0.5 as hunter2 failed'));

        self::assertSame(1, $code);
        self::assertSame('', $out);
        self::assertSame("state unavailable (RuntimeException)\n", $err);
    }
}
