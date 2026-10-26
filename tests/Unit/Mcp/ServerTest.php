<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\Envelope;
use Watcher\Mcp\Server;

final class ServerTest extends TestCase
{
    public function testParseErrorHasNullId(): void
    {
        $r = (new McpHarness())->run(['{nope'], [StubTool::plain()]);
        self::assertSame(-32700, $r[0]['error']['code']);
        self::assertNull($r[0]['id']);
    }

    public function testBatchIsInvalidRequest(): void
    {
        $r = (new McpHarness())->run(['[{"jsonrpc":"2.0","id":1,"method":"ping"}]'], [StubTool::plain()]);
        self::assertSame(-32600, $r[0]['error']['code']);
    }

    public function testOversizeLineIsDiscardedAndTheServerContinues(): void
    {
        $r = (new McpHarness())->run([str_repeat('a', Server::MAX_LINE + 10), ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping']], [StubTool::plain()]);
        self::assertSame(-32600, $r[0]['error']['code']);
        self::assertSame(5, $r[1]['id']);
        self::assertSame([], $r[1]['result']);
    }

    public function testRequestBeforeInitializeIsRefusedExceptPing(): void
    {
        $r = (new McpHarness())->run([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']], [StubTool::plain()], init: false);
        self::assertSame(-32600, $r[0]['error']['code']);
        self::assertSame('not_initialized', $r[0]['error']['message']);
        self::assertSame([], $r[1]['result']);
    }

    public function testInitializeEchoesAKnownVersionElseTheNewest(): void
    {
        $h = new McpHarness();
        $r = $h->run([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']]], [StubTool::plain()]);
        self::assertSame('2024-11-05', $r[0]['result']['protocolVersion']);
        self::assertSame(Server::PROTOCOL_VERSIONS[0], $r[1]['result']['protocolVersion']);
        self::assertSame(['tools' => ['listChanged' => false]], $r[0]['result']['capabilities']);
        self::assertSame(['name' => 'watcher', 'version' => '0.4.0'], $r[0]['result']['serverInfo']);
    }

    public function testNotificationsGetNoOutputUnknownOnesToo(): void
    {
        $r = (new McpHarness())->run([['jsonrpc' => '2.0', 'method' => 'notifications/whatever'], ['jsonrpc' => '2.0', 'method' => 'tools/list']], [StubTool::plain()]);
        self::assertSame([], $r);
    }

    /** @return array<string, array{string}> */
    public static function blockedMethods(): array
    {
        return array_combine($m = ['sampling/createMessage', 'logging/setLevel', 'completion/complete', 'roots/list', 'elicitation/create', 'resources/subscribe', 'resources/list', 'prompts/list', 'nope'], array_map(static fn (string $s): array => [$s], $m));
    }

    /** @dataProvider blockedMethods */
    public function testOtherMethodsAreNotFoundWhileDocsIsOff(string $method): void
    {
        $r = (new McpHarness())->run([['jsonrpc' => '2.0', 'id' => 1, 'method' => $method]], [StubTool::plain()]);
        self::assertSame(-32601, $r[0]['error']['code']);
    }

    public function testInvalidIdTypesAreNeverEchoed(): void
    {
        $r = (new McpHarness())->run([['jsonrpc' => '2.0', 'id' => ['x' => 'SECRET'], 'method' => 'ping'], ['jsonrpc' => '2.0', 'id' => 1.5, 'method' => 'ping'], ['jsonrpc' => '2.0', 'id' => true, 'method' => 'ping']], [StubTool::plain()]);
        foreach ($r as $x) {
            self::assertSame(-32600, $x['error']['code']);
            self::assertNull($x['id']);
        }
    }

    public function testToolsListAndCall(): void
    {
        $h = new McpHarness();
        $r = $h->run([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], McpHarness::call('watcher_status', [], 2)], [StubTool::plain()]);
        self::assertSame('watcher_status', $r[0]['result']['tools'][0]['name']);
        self::assertSame(['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false], $r[0]['result']['tools'][0]['annotations']);
        self::assertStringContainsString('"properties":{}', $h->raw);
        $env = McpHarness::envelope($r[1]);
        self::assertSame(Envelope::NOTICE, $env['notice']);
        self::assertTrue($env['untrusted_data']);
        self::assertSame('2026-10-25T09:12:55Z', $env['now']);
        self::assertSame(['ok' => true], $env['data']);
    }

    public function testStdoutCarriesOnlyProtocolLinesEvenWhenAToolEchoes(): void
    {
        $tool = new StubTool('watcher_status', 'inspect', ['type' => 'object', 'additionalProperties' => false, 'properties' => []], static function (): array {
            echo 'STRAY';

            return ['a' => 1];
        });
        $h = new McpHarness();
        $h->run([McpHarness::call('watcher_status')], [$tool]);
        foreach (explode("\n", trim($h->raw)) as $line) {
            self::assertIsArray(json_decode($line, true));
        }
        self::assertStringNotContainsString('STRAY', $h->raw);
        self::assertStringContainsString('stray output', $h->err);
    }

    public function testBadToolCallParamsAreInvalidParamsWithoutEcho(): void
    {
        $r = (new McpHarness())->run([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nope']], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 5]]], [StubTool::plain()]);
        self::assertSame(-32602, $r[0]['error']['code']);
        self::assertSame(-32602, $r[1]['error']['code']);
        self::assertStringNotContainsString('nope', json_encode($r) ?: '');
    }

    public function testEofEndsWithZeroAndBlankLinesAreIgnored(): void
    {
        $r = (new McpHarness())->run(['', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']], [StubTool::plain()]);
        self::assertCount(1, $r);
    }
}
