<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\LastReport;
use Watcher\Mcp\McpCommand;
use Watcher\State\FileStateStore;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FrozenClock;

final class McpCommandTest extends TestCase
{
    private string $dir;
    private string $cwd;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/mcp-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/state', 0700, true);
        $this->cwd = (string) getcwd();
        foreach (['T_K' => 'k', 'T_HOOK' => 'https://hooks.slack.com/services/T0/B0/xxxx', 'T_TOK' => 'tok'] as $k => $v) {
            putenv("$k=$v");
        }
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        foreach (['T_K', 'T_HOOK', 'T_TOK'] as $k) {
            putenv($k);
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function config(string $extra = '', string $name = 'watcher.php'): string
    {
        $p = $this->dir . '/' . $name;
        file_put_contents($p, "<?php\nuse Watcher\\Config\\Env;\nreturn [\n 'enabled' => true,\n 'service' => 'orders-api',\n 'schedule' => ['run_at' => '06:00'],\n 'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example.test', 'auth' => ['type' => 'bearer', 'token' => Env::string('T_TOK')]], 'stream_tags' => ['app' => 'a']],\n 'analysis' => ['api_keys' => ['gemini' => Env::string('T_K')], 'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'], ],\n 'state' => ['driver' => 'file', 'file' => ['path' => '" . $this->dir . "/state']],\n 'notify' => ['digest' => ['driver' => 'slack', 'webhook' => Env::string('T_HOOK')]],\n$extra];\n");
        chmod($p, 0644);

        return $p;
    }

    /**
     * @param list<string> $argv
     * @param list<array<string, mixed>> $messages
     * @return array{int, list<array<string, mixed>>, string, string}
     */
    private function serve(array $argv, array $messages = []): array
    {
        $in = McpHarness::mem();
        $out = McpHarness::mem();
        $err = McpHarness::mem();
        fwrite($in, '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}' . "\n");
        foreach ($messages as $m) {
            fwrite($in, json_encode($m, JSON_THROW_ON_ERROR) . "\n");
        }
        rewind($in);
        $code = McpCommand::main(['watcher', 'mcp', ...$argv], $in, $out, $err, new FrozenClock(new \DateTimeImmutable('2026-10-25T09:00:00Z')));
        rewind($out);
        rewind($err);
        $raw = (string) stream_get_contents($out);
        $lines = $raw === '' ? [] : array_map(static fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), explode("\n", rtrim($raw, "\n")));

        return [$code, $lines, $raw, (string) stream_get_contents($err)];
    }

    /** @return array<string, array{list<string>}> */
    public static function badOptions(): array
    {
        return ['none' => [[]], 'json' => [['--config=x', '--json']], 'now' => [['--now=2026-01-01T00:00:00Z']], 'dry-run' => [['--dry-run']], 'both' => [['--setup', '--config=x']], 'twice' => [['--setup', '--setup']], 'missing file' => [['--config=/nonexistent/x.php']], 'positional' => [['x']]];
    }

    /**
     * @param list<string> $args
     * @dataProvider badOptions
     */
    public function testEveryOtherOptionIsAUsageError(array $args): void
    {
        [$code, , $raw, $err] = $this->serve($args);
        self::assertSame(2, $code);
        self::assertSame('', $raw);
        self::assertStringContainsString('usage:', $err);
    }

    public function testInvalidConfigExitsTwoWithAnEmptyStdoutAndNamesTheSetting(): void
    {
        $p = $this->dir . '/bad.php';
        file_put_contents($p, "<?php\nreturn ['service' => 'x', 'selection' => ['min_levl' => 'error']];\n");
        [$code, $lines, $raw, $err] = $this->serve(['--config=' . $p]);
        self::assertSame(2, $code);
        self::assertSame('', $raw);
        self::assertSame([], $lines);
        self::assertStringContainsString('invalid config:', $err);
    }

    public function testAFleetFileAndABadMcpSectionAreUsageErrors(): void
    {
        $f = $this->dir . '/fleet.php';
        file_put_contents($f, "<?php\nreturn ['fleet' => []];\n");
        [$code, , $raw, $err] = $this->serve(['--config=' . $f]);
        self::assertSame([2, ''], [$code, $raw]);
        self::assertStringContainsString('fleet', $err);
        foreach (["'mcp' => ['bogus' => 1],\n" => 'mcp.bogus', "'mcp' => ['groups' => []],\n" => 'mcp.groups', "'mcp' => ['groups' => ['inspect', 'inspect']],\n" => 'mcp.groups', "'mcp' => ['allow_dry_run' => 'yes'],\n" => 'mcp.allow_dry_run'] as $extra => $named) {
            [$code, , $raw, $err] = $this->serve(['--config=' . $this->config($extra)]);
            self::assertSame([2, ''], [$code, $raw], $named);
            self::assertStringContainsString($named, $err);
        }
    }

    public function testAValidConfigServesThirteenToolsAndReadsTheStoreWithoutChangingIt(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-25T09:00:00Z'));
        $w = new FileStateStore($this->dir . '/state', $clock);
        $w->put(LastReport::key('orders-api'), ['v' => 1, 'windowStart' => '2026-10-24T06:00:00+00:00', 'windowEnd' => '2026-10-25T06:00:00+00:00', 'outcome' => 'reported', 'at' => '2026-10-25T06:03:00+00:00', 'counts' => ['events' => 5, 'groups' => 2, 'candidates' => 1, 'findings' => 1], 'findings' => [['fp' => 'a3f9c01d22be', 'severity' => 'error', 'title' => 'Timeouts']]], 3600);
        $w->put(Keys::verdict('orders-api', 'a3f9c01d22be'), ['triage' => 'interesting', 'atCount' => 5, 'finding' => ['kind' => 'bug', 'severity' => 'error', 'title' => 'Timeouts', 'evidence' => 'e', 'suspected_cause' => 'c', 'suggested_action' => 'a', 'confidence' => 0.9, 'escalated' => false, 'how_to_check' => 'h', 'related_checks' => []]], 3600);
        $before = $this->snapshot();
        [$code, $lines, , $err] = $this->serve(['--config=' . $this->config()], [
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            McpHarness::call('watcher_latest_report', [], 2),
            McpHarness::call('watcher_finding', ['fp' => 'a3f9c01d22be'], 3),
            McpHarness::call('watcher_status', [], 4),
            McpHarness::call('watcher_explain_outcome', ['code' => 'skipped_done'], 5),
        ]);
        self::assertSame(0, $code);
        self::assertSame('', $err);
        self::assertCount(13, $lines[1]['result']['tools']);
        self::assertNotContains('watcher_dry_run', array_column($lines[1]['result']['tools'], 'name'));
        $latest = McpHarness::envelope($lines[2]);
        self::assertSame('orders-api', $latest['service']);
        self::assertSame('a3f9c01d22be', $latest['data']['findings'][0]['fp']);
        self::assertTrue(McpHarness::envelope($lines[3])['data']['found']);
        self::assertSame('unknown', McpHarness::envelope($lines[4])['data']['state']);
        self::assertSame(0, McpHarness::envelope($lines[5])['data']['exit_code']);
        self::assertSame($before, $this->snapshot(), 'the state directory must be byte-identical');
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $out = [];
        foreach (glob($this->dir . '/state/*') ?: [] as $f) {
            $out[basename($f)] = md5_file($f) . filemtime($f);
        }

        return $out;
    }

    public function testGroupsSelectTheTools(): void
    {
        [$code, $lines] = $this->serve(['--config=' . $this->config("'mcp' => ['groups' => ['inspect']],\n")], [['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/list']]);
        self::assertSame(0, $code);
        self::assertSame(['watcher_status', 'watcher_check', 'watcher_latest_report', 'watcher_finding', 'watcher_hygiene_report'], array_column($lines[1]['result']['tools'], 'name'));
        self::assertSame(-32601, $lines[2]['error']['code']);
        [, $lines, , $err] = $this->serve(['--config=' . $this->config("'mcp' => ['allow_dry_run' => true],\n")], [['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']]);
        self::assertCount(13, $lines[1]['result']['tools']);
        self::assertStringContainsString('no dry-run adapter', $err);
    }

    public function testSetupModeNeedsNoConfigurationAndServesEightTools(): void
    {
        chdir($this->dir);
        [$code, $lines, , $err] = $this->serve(['--setup'], [
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            McpHarness::call('watcher_first_day', ['host' => 'plain_php'], 2),
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/list'],
        ]);
        self::assertSame([0, ''], [$code, $err]);
        self::assertSame(['watcher_setup_plan', 'watcher_validate_config', 'watcher_generate_config', 'watcher_first_day', 'watcher_rollout_check', 'watcher_search_docs', 'watcher_explain_config', 'watcher_explain_outcome'], array_column($lines[1]['result']['tools'], 'name'));
        self::assertNull(McpHarness::envelope($lines[2])['service']);
        $names = array_column($lines[3]['result']['prompts'], 'name');
        self::assertContains('onboard-new-service', $names);
        self::assertNotContains('why-is-the-digest-quiet', $names); // its tools (status, check, latest report) are not served in setup mode
    }
}
