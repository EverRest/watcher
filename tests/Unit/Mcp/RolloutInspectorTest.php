<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Mcp\ConfigFileReader;
use Watcher\Mcp\ConfigFileValidator;
use Watcher\Mcp\PathPolicy;
use Watcher\Mcp\ReadOnlyStateStore;
use Watcher\Mcp\RolloutInspector;
use Watcher\Mcp\ToolError;
use Watcher\Tests\Fakes\RecordingStateStore;

final class RolloutInspectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/mcp-ro-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755);
        putenv('T_TOK=tok');
        putenv('T_K=k');
        putenv('T_HOOK=https://hooks.slack.com/services/T0/B0/xxxx');
    }

    protected function tearDown(): void
    {
        putenv('T_TOK');
        putenv('T_K');
        putenv('T_HOOK');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /**
     * @param array<string, bool> $ext
     * @param list<string> $classes
     */
    private function inspector(array $ext = ['redis' => true, 'zlib' => true, 'pdo' => true], array $classes = [], int $php = 80300, string $version = '0.4.0', ?\Closure $store = null, ?string $composerDir = null): RolloutInspector
    {
        return new RolloutInspector(
            new PathPolicy([$this->dir]),
            new ConfigFileReader(),
            new ConfigFileValidator(),
            static fn (string $e): bool => $ext[$e] ?? false,
            static fn (string $c): bool => in_array($c, $classes, true),
            $store ?? static fn (WatchConfig $c): \Watcher\State\StateStore => throw new \LogicException('no connection expected'),
            $version,
            $php,
            $composerDir ?? $this->dir,
        );
    }

    private function config(string $state = 'file', bool $enabled = false): string
    {
        $p = $this->dir . '/watcher.php';
        $driver = match ($state) {
            'redis' => "['driver' => 'redis', 'redis' => ['client' => 'phpredis']]",
            'pdo' => "['driver' => 'pdo', 'pdo' => ['dsn' => 'mysql:host=x;dbname=y']]",
            default => "['driver' => 'file', 'file' => ['path' => '" . $this->dir . "/state']]",
        };
        file_put_contents($p, "<?php\nreturn [\n 'enabled' => " . ($enabled ? 'true' : 'false') . ",\n 'service' => 'orders-api',\n 'schedule' => ['run_at' => '06:00'],\n 'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example.test', 'auth' => ['type' => 'bearer', 'token' => \\Watcher\\Config\\Env::string('T_TOK')]], 'stream_tags' => ['app' => 'a']],\n 'analysis' => ['api_key' => \\Watcher\\Config\\Env::string('T_K'), 'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'], 'prices' => ['m1' => [1.0, 2.0, 0.0, 0.0], 'm2' => [1.0, 2.0, 0.0, 0.0], 'm3' => [1.0, 2.0, 0.0, 0.0]]],\n 'state' => $driver,\n 'notify' => ['digest' => ['driver' => 'slack', 'webhook' => \\Watcher\\Config\\Env::string('T_HOOK')]],\n];\n");
        chmod($p, 0644);

        return $p;
    }

    /**
     * @param array{checks: list<array{name: string, status: string, reason: string|null}>} $r
     * @return array{name: string, status: string, reason: string|null}
     */
    private static function check(array $r, string $name): array
    {
        foreach ($r['checks'] as $c) {
            if ($c['name'] === $name) {
                return $c;
            }
        }
        self::fail($name);
    }

    public function testPhpBelowTheFloorFails(): void
    {
        $r = $this->inspector(php: 80199)->inspect(null, false);
        self::assertSame(['name' => 'php_version', 'status' => 'fail', 'reason' => 'php 8.2 required'], $r['checks'][0]);
        self::assertFalse($r['ok']);
        self::assertSame('pass', $this->inspector()->inspect(null, false)['checks'][0]['status']);
    }

    public function testExtensionMatrixByDriver(): void
    {
        $p = $this->config('redis');
        self::assertSame('pass', self::check($this->inspector()->inspect($p, false), 'extensions')['status']);
        self::assertSame('ext-redis missing', self::check($this->inspector(['zlib' => true])->inspect($p, false), 'extensions')['reason']);
        self::assertSame('warn', self::check($this->inspector(['redis' => true])->inspect($p, false), 'extensions')['status']);
        self::assertSame('ext-zlib missing (compression off)', self::check($this->inspector(['redis' => true])->inspect($p, false), 'extensions')['reason']);
        $p = $this->config('pdo');
        self::assertSame('ext-pdo missing', self::check($this->inspector([])->inspect($p, false), 'extensions')['reason']);
        self::assertSame('pdo driver missing', self::check($this->inspector(['pdo' => true])->inspect($p, false), 'extensions')['reason']);
        self::assertSame('pass', self::check($this->inspector(['pdo' => true, 'pdo_mysql' => true])->inspect($p, false), 'extensions')['status']);
        self::assertSame('pass', self::check($this->inspector([])->inspect($this->config('file'), false), 'extensions')['status']);
    }

    /** @return array<string, array{string, string, string|null}> */
    public static function constraints(): array
    {
        return ['caret ok' => ['^0.4', '0.4.0', 'pass'], 'caret excludes' => ['^0.1', '0.4.0', 'fail'], 'caret 0.4.0 allows 0.4.2' => ['^0.4.0', '0.4.2', 'pass'], 'tilde' => ['~0.4.0', '0.5.0', 'fail'], 'exact' => ['0.4.0', '0.4.0', 'pass'], 'star' => ['*', '9.9.9', 'pass'], 'or' => ['^0.1 || ^0.4', '0.4.1', 'pass'], 'caret 1' => ['^1.2', '1.9.0', 'pass'], 'wildcard' => ['0.4.*', '0.4.9', 'pass'], 'gte' => ['>=0.3', '0.4.0', 'pass']];
    }

    /** @dataProvider constraints */
    public function testComposerConstraint(string $constraint, string $installed, ?string $status): void
    {
        file_put_contents($this->dir . '/composer.json', json_encode(['require' => ['everrest/watcher' => $constraint]]));
        $c = self::check($this->inspector(version: $installed)->inspect(null, false), 'composer');
        self::assertSame($status, $c['status']);
        if ($status === 'fail') {
            self::assertSame('constraint does not allow ' . $installed, $c['reason']);
        }
    }

    public function testComposerMissingConstraintOrFile(): void
    {
        file_put_contents($this->dir . '/composer.json', json_encode(['require' => ['php' => '^8.2']]));
        self::assertSame('constraint missing', self::check($this->inspector()->inspect(null, false), 'composer')['reason']);
        unlink($this->dir . '/composer.json');
        self::assertSame('skip', self::check($this->inspector(composerDir: $this->dir . '/a/b')->inspect(null, false), 'composer')['status']);
    }

    public function testConfigAndMasterSwitch(): void
    {
        $none = $this->inspector()->inspect(null, false);
        self::assertSame('no config in allowed roots', self::check($none, 'config')['reason']);
        $off = $this->inspector()->inspect($this->config('file', false), false);
        self::assertSame('pass', self::check($off, 'config')['status']);
        self::assertSame('enabled=false', self::check($off, 'master_switch')['reason']);
        self::assertSame('enabled=true', self::check($this->inspector()->inspect($this->config('file', true), false), 'master_switch')['reason']);
        file_put_contents($this->dir . '/bad.php', "<?php\nreturn ['service' => 'x', 'bogus' => 1];\n");
        chmod($this->dir . '/bad.php', 0644);
        $bad = $this->inspector()->inspect($this->dir . '/bad.php', false);
        self::assertSame('fail', self::check($bad, 'config')['status']);
        self::assertSame('invalid: bogus', self::check($bad, 'config')['reason']);
    }

    public function testConnectFalseNeverTouchesTheStoreAndTrueReadsOnceThroughTheReadOnlyStore(): void
    {
        $p = $this->config();
        self::assertSame(['name' => 'state_store', 'status' => 'skip', 'reason' => 'not requested'], self::check($this->inspector()->inspect($p, false), 'state_store'));

        $spy = new RecordingStateStore(\Watcher\Tests\Unit\Mcp\InspectFixtures::store());
        $r = $this->inspector(store: static fn (WatchConfig $c): \Watcher\State\StateStore => new ReadOnlyStateStore($spy))->inspect($p, true);
        self::assertSame('read-only probe', self::check($r, 'state_store')['reason']);
        self::assertCount(1, $spy->calls);
        self::assertSame('get', $spy->calls[0][0]);
        self::assertSame([], $spy->writes());

        $r = $this->inspector(store: static fn (WatchConfig $c): \Watcher\State\StateStore => throw new \RuntimeException('redis://:pw@h'))->inspect($p, true);
        self::assertSame('unreadable', self::check($r, 'state_store')['reason']);
        self::assertStringNotContainsString('pw@h', json_encode($r) ?: '');
    }

    public function testConnectWithAPathOutsideTheRootsIsRefusedWithoutAConnection(): void
    {
        $connected = false;
        $i = $this->inspector(store: static function () use (&$connected): \Watcher\State\StateStore {
            $connected = true;

            throw new \LogicException();
        });
        try {
            $i->inspect('/etc/passwd', true);
            self::fail();
        } catch (ToolError $e) {
            self::assertSame('path_not_allowed', $e->errorCode);
        }
        self::assertFalse($connected);
    }
}
