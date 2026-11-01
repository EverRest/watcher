<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\McpCommand;
use Watcher\Tests\Unit\Mcp\FakePipeline;
use Watcher\Tests\Unit\Mcp\McpHarness;

/**
 * US7 AC7, SC-015, T065: the worked onboarding conversation of quickstart.md, as a script over `watcher mcp --setup`.
 * Protocol-only stdout, empty stderr, one refusal for a pasted secret, no secret value in any frame.
 */
final class McpOnboardingConversationTest extends TestCase
{
    /** Built from parts so no token-shaped literal sits in this file (as in SecretDumpTest). */
    private const SECRET = 'https://hooks.slack.com/services/' . 'T0ABCDEF0/B0ABCDEF0/' . 'abcdefghijklmnopqrstuvwx';

    private string $cwd;
    private string $dir;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/mcp-conversation-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0700, true);
        chdir($this->dir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /**
     * @param list<array<string, mixed>> $calls
     * @return array{0: list<array<string, mixed>>, 1: string, 2: string} decoded replies, raw stdout, stderr
     */
    private function converse(array $calls): array
    {
        $in = McpHarness::mem();
        $out = McpHarness::mem();
        $err = McpHarness::mem();
        $messages = array_merge([
            ['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ], $calls);
        foreach ($messages as $m) {
            fwrite($in, json_encode($m, JSON_THROW_ON_ERROR) . "\n");
        }
        rewind($in);
        $code = McpCommand::main(['w', 'mcp', '--setup'], $in, $out, $err, null, null, static fn (): FakePipeline => new FakePipeline());
        self::assertSame(0, $code);
        rewind($out);
        rewind($err);
        $raw = (string) stream_get_contents($out);
        $stderr = (string) stream_get_contents($err);
        $replies = array_map(static fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), explode("\n", rtrim($raw, "\n")));

        return [array_slice($replies, 1), $raw, $stderr];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function tool(string $name, array $args, int $id): array
    {
        return McpHarness::call($name, $args, $id);
    }

    /**
     * @param array<string, mixed> $reply
     * @return array<string, mixed>
     */
    private static function data(array $reply): array
    {
        return McpHarness::envelope($reply)['data'];
    }

    public function testTheOnboardingConversationRunsWithoutARefusalAndWritesOnlyItsOwnFile(): void
    {
        // The env names the snippet reads are set to dummy values, as the operator's secret store would do.
        $env = ['WATCHER_LOKI_URL' => 'https://loki.example.test', 'WATCHER_LOKI_TOKEN' => 'dummy', 'WATCHER_ANTHROPIC_KEY' => 'dummy', 'WATCHER_SLACK_WEBHOOK' => 'dummy', 'WATCHER_REDIS_URL' => 'redis://localhost:6379'];
        foreach ($env as $k => $v) {
            putenv("$k=$v");
        }

        try {
            // Turns 1 to 4: the plan, then the configuration the operator saves.
            [$first, $raw1, $err1] = $this->converse([
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/get', 'params' => ['name' => 'onboard-new-service', 'arguments' => ['host' => 'laravel']]],
                self::tool('watcher_setup_plan', ['host' => 'laravel', 'source' => 'loki', 'notifier' => 'slack', 'state' => 'redis'], 3),
                self::tool('watcher_generate_config', ['service' => 'orders-api', 'run_at' => '06:00', 'timezone' => 'Europe/Kyiv', 'host' => 'laravel', 'source' => 'loki', 'notifier' => 'slack', 'state' => 'redis', 'stream_tags' => ['app' => 'orders-api'], 'level_mode' => 'label'], 4),
            ]);
            self::assertSame('', $err1);
            self::assertCount(8, $first[0]['result']['tools']);
            self::assertSame(11, count(self::data($first[2])['steps']));
            file_put_contents($this->dir . '/config/watcher.php', self::data($first[3])['php_snippet']);

            // Turns 5 to 10: validate and check the saved file, the first day, a pasted secret, an unknown key.
            [$second, $raw2, $err2] = $this->converse([
                self::tool('watcher_validate_config', ['path' => 'config/watcher.php'], 5),
                self::tool('watcher_rollout_check', [], 6),
                self::tool('watcher_first_day', ['host' => 'laravel'], 7),
                self::tool('watcher_explain_outcome', ['code' => 'baselined'], 8),
                self::tool('watcher_generate_config', ['service' => self::SECRET, 'run_at' => '06:00', 'timezone' => 'Europe/Kyiv', 'host' => 'laravel', 'source' => 'loki', 'notifier' => 'slack', 'state' => 'redis'], 9),
                self::tool('watcher_explain_config', ['key' => 'selection.min_levl'], 10),
            ]);
        } finally {
            foreach (array_keys($env) as $k) {
                putenv($k);
            }
        }

        // Exactly one refusal is logged, by class name only (the paste is never written anywhere).
        self::assertSame("watcher [error] Watcher\\Mcp\\SecretRefused in watcher_generate_config\n", $err2);
        // Setup mode approves no PHP file (security review F1): the saved file is checked with `--config`, not here.
        self::assertSame('path_not_allowed', McpHarness::envelope($second[0])['error']);
        self::assertSame(6, count(self::data($second[2])['steps']));
        self::assertSame('baselined', self::data($second[3])['code']);
        self::assertSame('secret_refused', McpHarness::envelope($second[4])['error']);
        self::assertSame(false, self::data($second[5])['found']);
        self::assertContains('selection.min_level', self::data($second[5])['did_you_mean']);

        foreach ([$raw1, $raw2] as $raw) {
            self::assertStringNotContainsString(self::SECRET, $raw);
        }
    }
}
