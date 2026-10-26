<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\McpCommand;

/** T045 / SC-010: the secret corpus through every string input of every onboarding and docs tool and every prompt argument. */
final class McpSecretRefusalTest extends TestCase
{
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = (string) getcwd();
        chdir(sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
    }

    /** @return array<string, array{string}> */
    public static function secrets(): array
    {
        return array_map(static fn (string $s): array => [$s], (require __DIR__ . '/../../Fixtures/mcp/secret-corpus.php')['secrets']);
    }

    /**
     * Every (tool, arguments) pair that carries $v in a string position.
     *
     * @return list<array{string, array<string, mixed>}>
     */
    private static function calls(string $v): array
    {
        $base = ['service' => 'orders-api', 'run_at' => '06:00', 'host' => 'plain_php', 'source' => 'loki', 'notifier' => 'slack', 'state' => 'file'];

        return [
            ['watcher_setup_plan', ['host' => $v, 'source' => 'loki', 'notifier' => 'slack', 'state' => 'file']],
            ['watcher_setup_plan', ['host' => 'laravel', 'source' => 'loki', 'notifier' => 'slack', 'state' => 'file', 'features' => [$v]]],
            ['watcher_first_day', ['host' => $v]],
            ['watcher_generate_config', ['service' => $v] + $base],
            ['watcher_generate_config', ['environment' => $v] + $base],
            ['watcher_generate_config', ['timezone' => $v] + $base],
            ['watcher_generate_config', ['run_at' => $v] + $base],
            ['watcher_generate_config', ['stream_tags' => ['app' => $v]] + $base],
            ['watcher_generate_config', ['stream_tags' => [$v => 'x']] + $base],
            ['watcher_generate_config', ['env_names' => ['loki_token' => $v]] + $base],
            ['watcher_generate_config', ['env_names' => [$v => 'WATCHER_X']] + $base],
            ['watcher_validate_config', ['path' => $v]],
            ['watcher_rollout_check', ['path' => $v]],
            ['watcher_search_docs', ['query' => $v]],
            ['watcher_explain_config', ['key' => $v]],
            ['watcher_explain_outcome', ['code' => $v]],
        ];
    }

    /** @dataProvider secrets */
    public function testEveryToolInputRefusesTheSecretAndNeverEchoesIt(string $secret): void
    {
        $msgs = [['jsonrpc' => '2.0', 'method' => 'notifications/initialized']];
        $id = 1;
        foreach (self::calls($secret) as [$tool, $args]) {
            $msgs[] = McpHarness::call($tool, $args, $id++);
        }
        foreach (['host', 'source', 'notifier', 'state'] as $arg) {
            $msgs[] = ['jsonrpc' => '2.0', 'id' => $id++, 'method' => 'prompts/get', 'params' => ['name' => 'onboard-new-service', 'arguments' => [$arg => $secret]]];
        }
        [$lines, $raw, $err] = $this->serve($msgs);
        $responses = array_slice($lines, 1);
        self::assertCount($id - 1, $responses);
        foreach ($responses as $i => $r) {
            if (isset($r['error'])) {
                self::assertSame(-32602, $r['error']['code'], "prompt call #$i");
                self::assertSame('Give the name of the environment variable, not its value.', $r['error']['message']);
            } else {
                self::assertTrue($r['result']['isError'], "call #$i not refused");
                self::assertSame('secret_refused', McpHarness::envelope($r)['error'], "call #$i");
            }
        }
        foreach ([$secret, Normalized::of($secret)] as $needle) {
            self::assertStringNotContainsString($needle, $raw);
            self::assertStringNotContainsString($needle, $err);
            self::assertStringNotContainsString(substr($needle, 0, 20), $raw);
        }
        self::assertStringContainsString('SecretRefused in', $err);
    }

    public function testCredentialNamedPropertiesAndNonWatcherEnvNamesAreInvalidParams(): void
    {
        $msgs = [['jsonrpc' => '2.0', 'method' => 'notifications/initialized']];
        foreach (['token', 'api_key', 'webhook', 'password', 'secret'] as $i => $prop) {
            $msgs[] = McpHarness::call('watcher_generate_config', [$prop => 'orders'] + ['service' => 'orders-api', 'run_at' => '06:00', 'host' => 'plain_php', 'source' => 'loki', 'notifier' => 'slack', 'state' => 'file'], $i + 1);
        }
        $msgs[] = McpHarness::call('watcher_generate_config', ['env_names' => ['loki_token' => 'MY_TOKEN'], 'service' => 'orders-api', 'run_at' => '06:00', 'host' => 'plain_php', 'source' => 'loki', 'notifier' => 'slack', 'state' => 'file'], 9);
        [$lines] = $this->serve($msgs);
        foreach (array_slice($lines, 1) as $r) {
            self::assertSame(-32602, $r['error']['code']);
        }
        self::assertSame('invalid params: token', $lines[1]['error']['message']);
    }

    public function testControlRunWithoutTheGuardLeaksSoTheTestCanSeeALeak(): void
    {
        $secret = 'xoxb-' . '123456789012-1234567890123-abcdefghijklmnopqrstuvwx';
        $echo = new StubTool('watcher_echo', 'inspect', ['type' => 'object', 'additionalProperties' => true, 'properties' => []], static fn (array $a): array => ['echo' => $a['service'] ?? null]); // an unguarded group
        $h = new McpHarness();
        $h->run([McpHarness::call('watcher_echo', ['service' => $secret])], [$echo]);
        self::assertStringContainsString($secret, $h->raw);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array{list<array<string, mixed>>, string, string}
     */
    private function serve(array $messages): array
    {
        $in = McpHarness::mem();
        $out = McpHarness::mem();
        $err = McpHarness::mem();
        fwrite($in, '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{}}' . "\n");
        foreach ($messages as $m) {
            fwrite($in, json_encode($m, JSON_THROW_ON_ERROR) . "\n");
        }
        rewind($in);
        self::assertSame(0, McpCommand::main(['w', 'mcp', '--setup'], $in, $out, $err));
        rewind($out);
        rewind($err);
        $raw = (string) stream_get_contents($out);

        return [array_map(static fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim($raw))), $raw, (string) stream_get_contents($err)];
    }
}

/** @internal */
final class Normalized
{
    public static function of(string $s): string
    {
        return \Watcher\Reduce\Normalizer::canonical($s);
    }
}
