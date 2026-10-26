<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\ConfigFileReader;
use Watcher\Mcp\ConfigFileValidator;
use Watcher\Mcp\ConfigGenerator;
use Watcher\Mcp\PathPolicy;
use Watcher\Mcp\Tools\ValidateConfigTool;
use Watcher\Mcp\ToolError;

final class ConfigFileValidatorTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/mcp-val-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755);
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $n) {
            putenv($n);
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function write(string $name, string $code): string
    {
        $p = $this->dir . '/' . $name;
        file_put_contents($p, $code);
        chmod($p, 0644);

        return $p;
    }

    private function snippet(): string
    {
        $r = (new ConfigGenerator())->generate(['service' => 'orders-api', 'run_at' => '06:00', 'host' => 'plain_php', 'source' => 'loki', 'notifier' => 'slack', 'state' => 'file', 'stream_tags' => ['app' => 'orders-api']]);
        foreach ($r['env_vars'] as $v) {
            $val = match (true) {
                $v['name'] === 'WATCHER_ENABLED' => '',
                str_contains($v['name'], 'WEBHOOK') => 'https://hooks.slack.com/services/T0/B0/xxxx',
                str_contains($v['name'], 'PRICE') => '1.5',
                str_contains($v['name'], 'LOKI_URL') => 'https://loki.example.test',
                str_contains($v['name'], 'STATE_PATH') => $this->dir . '/state',
                default => 'dummy-x',
            };
            if ($val !== '') {
                putenv($v['name'] . '=' . $val);
                $this->env[] = $v['name'];
            }
        }

        return $r['php_snippet'];
    }

    /**
     * Validates through the policy of the server's configured file, which is the file itself: no sibling is approved (F1).
     *
     * @return array<string, mixed>
     */
    private function validate(string $path): array
    {
        return $this->tool($path)->call(['path' => $path]);
    }

    private function tool(string $configFile): ValidateConfigTool
    {
        return new ValidateConfigTool(PathPolicy::forFile($configFile), new ConfigFileReader(), new ConfigFileValidator());
    }

    public function testASiblingPhpFileIsRefusedAndNotRun(): void
    {
        $config = $this->write('watcher.php', $this->snippet());
        $marker = $this->dir . '/marker';
        $sibling = $this->write('other.php', "<?php\nfile_put_contents('$marker', 'ran');\nreturn [];\n");
        try {
            $this->tool($config)->call(['path' => $sibling]);
            self::fail('a sibling file was approved');
        } catch (ToolError $e) {
            self::assertSame('path_not_allowed', $e->errorCode);
        }
        self::assertFileDoesNotExist($marker);
    }

    public function testAValidFileReportsFlagsOff(): void
    {
        $d = $this->validate($this->write('ok.php', $this->snippet()));
        self::assertTrue($d['valid']);
        self::assertSame([], $d['problems']);
        self::assertContains('enabled', $d['flags_off']);
        self::assertSame('config', $d['stage']);
        self::assertSame('done', $d['secret_scan']);
        self::assertGreaterThan(100, $d['file']['bytes']);
    }

    public function testAnUnknownKeyIsNamedWithASuggestion(): void
    {
        $code = str_replace("\n];\n", "\n    'selection' => ['min_levl' => 'error'],\n];\n", $this->snippet());
        $d = $this->validate($this->write('unknown.php', $code));
        self::assertFalse($d['valid']);
        self::assertSame('unknown_key', $d['problems'][0]['code']);
        self::assertSame('selection.min_levl', $d['problems'][0]['key']);
        self::assertContains('selection.min_level', $d['problems'][0]['did_you_mean']);
    }

    public function testAMissingPriceIsAnInvalidValueNamingPrices(): void
    {
        $this->snippet();
        putenv('WATCHER_PRICE_TRIAGE_IN');
        putenv('WATCHER_MODEL_TRIAGE');
        $d = $this->validate($this->write('price.php', (new ConfigGenerator())->generate(['service' => 'orders-api', 'run_at' => '06:00', 'host' => 'plain_php', 'source' => 'loki', 'notifier' => 'slack', 'state' => 'file'])['php_snippet']));
        self::assertFalse($d['valid']);
        self::assertContains($d['problems'][0]['code'], ['invalid_value', 'missing_key']);
        self::assertStringNotContainsString('hooks.slack.com', json_encode($d) ?: '');
    }

    public function testPlainTextSecretsAreReportedByKeyPathAndTheLiteralNeverAppears(): void
    {
        $lit = 'LITERAL' . 'SECRETVALUE0123';
        $code = str_replace("Env::string('WATCHER_SLACK_WEBHOOK')", "'https://hooks.slack.com/services/T0/B0/$lit'", $this->snippet());
        $code = str_replace("Env::string('WATCHER_ANTHROPIC_KEY')", "'sk-$lit'", $code);
        $code = str_replace("'token' => Env::string('WATCHER_LOKI_TOKEN')", "'token' => '$lit'", $code);
        $d = $this->validate($this->write('secrets.php', $code));
        $codes = array_column(array_filter($d['problems'], static fn (array $p): bool => $p['code'] === 'plain_text_secret'), 'key');
        self::assertEqualsCanonicalizing(['analysis.api_key', 'notify.digest.webhook', 'source.loki.auth.token'], $codes);
        self::assertFalse($d['valid']);
        self::assertStringNotContainsString($lit, json_encode($d) ?: '');
    }

    public function testSyntaxErrorsAndThrowingFilesGiveNoMessage(): void
    {
        $a = $this->validate($this->write('syntax.php', "<?php\nreturn [ 'a' => ;\n"));
        $b = $this->validate($this->write('throws.php', "<?php\nthrow new \\RuntimeException('PASSWORD=hunter2');\n"));
        $c = $this->validate($this->write('notarray.php', "<?php\nreturn 5;\n"));
        $d = $this->validate($this->write('noopen.php', "return [];\n"));
        foreach ([$a, $b, $c, $d] as $r) {
            self::assertSame('config_file_error', $r['problems'][0]['code']);
            self::assertStringNotContainsString('hunter2', json_encode($r) ?: '');
        }
    }

    public function testAnOutputtingFileDoesNotReachStdout(): void
    {
        ob_start();
        $d = $this->validate($this->write('echo.php', "<?php\necho 'LEAK';\nreturn [];\n"));
        $out = ob_get_clean();
        self::assertSame('', $out);
        self::assertFalse($d['valid']);
    }

    public function testOversizeAndPolicyRefusalsAreTypedErrors(): void
    {
        $p = $this->write('big.php', "<?php\n/*" . str_repeat('a', 300 * 1024) . "*/\nreturn [];\n");
        try {
            $this->validate($p);
            self::fail();
        } catch (ToolError $e) {
            self::assertSame('file_too_large', $e->errorCode);
        }
        foreach (['/etc/passwd', $this->dir . '/../x.php', $this->dir . '/missing.php'] as $bad) {
            try {
                $this->validate($bad);
                self::fail($bad);
            } catch (ToolError $e) {
                self::assertSame('path_not_allowed', $e->errorCode);
            }
        }
    }

    public function testNoStringLiteralOfTheFileLeavesTheResult(): void
    {
        $marker = 'ZZ_UNIQUE_MARKER_ZZ';
        $code = str_replace("'service' => 'orders-api'", "'service' => '$marker'", $this->snippet());
        $code = str_replace("\n];\n", "\n    'selection' => ['min_level' => '$marker'],\n];\n", $code);
        $d = $this->validate($this->write('marker.php', $code));
        self::assertFalse($d['valid']);
        self::assertStringNotContainsString($marker, json_encode($d) ?: '');
    }
}
