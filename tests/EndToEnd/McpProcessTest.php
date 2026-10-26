<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;

/** T032: the real process: one JSON line per response, nothing on stderr, exit 0 at EOF, usage errors exit 2. */
final class McpProcessTest extends TestCase
{
    private const BIN = __DIR__ . '/../../bin/watcher';

    /** @return array{int, string, string} */
    private function proc(string $args, string $stdin): array
    {
        $cwd = sys_get_temp_dir();
        $p = proc_open([PHP_BINARY, self::BIN, 'mcp', ...($args === '' ? [] : explode(' ', $args))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        self::assertIsResource($p);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($p), $out, $err];
    }

    public function testSetupModeOverRealPipes(): void
    {
        $in = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}' . "\n"
            . '{"jsonrpc":"2.0","method":"notifications/initialized"}' . "\n"
            . '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' . "\n"
            . '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"watcher_explain_outcome","arguments":{"code":"lock_lost"}}}' . "\n";
        [$code, $out, $err] = $this->proc('--setup', $in);
        self::assertSame(0, $code);
        self::assertSame('', $err);
        $lines = explode("\n", rtrim($out, "\n"));
        self::assertCount(3, $lines);
        foreach ($lines as $l) {
            self::assertIsArray(json_decode($l, true));
        }
        self::assertCount(8, json_decode($lines[1], true)['result']['tools']);
        self::assertSame('lock_lost', json_decode(json_decode($lines[2], true)['result']['content'][0]['text'], true)['data']['code']);
    }

    public function testUsageErrorsExitTwoWithAnEmptyStdout(): void
    {
        foreach (['', '--json', '--config=/nonexistent.php', '--setup --config=x'] as $args) {
            [$code, $out, $err] = $this->proc($args, '');
            self::assertSame([2, ''], [$code, $out], $args);
            self::assertStringContainsString('usage:', $err);
        }
    }
}
