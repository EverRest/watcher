<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\McpCommand;
use Watcher\Mcp\Server;

/** FR-014/021, T004/T041: the tools the server lists equal contracts/tools.schema.json (inputs, groups, annotations). */
final class ToolSchemaDriftTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../../specs/015-mcp-readonly/contracts/tools.schema.json';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/mcp-drift-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/state', 0700, true);
        foreach (['T_K' => 'k', 'T_HOOK' => 'https://hooks.slack.com/services/T0/B0/xxxx', 'T_TOK' => 'tok'] as $k => $v) {
            putenv("$k=$v");
        }
    }

    protected function tearDown(): void
    {
        foreach (['T_K', 'T_HOOK', 'T_TOK'] as $k) {
            putenv($k);
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array<string, array<string, mixed>> name => listed tool, with the dry run on */
    private function listed(): array
    {
        $p = $this->dir . '/c.php';
        file_put_contents($p, "<?php\nuse Watcher\\Config\\Env;\nreturn [\n 'enabled' => true,\n 'service' => 'orders-api',\n 'schedule' => ['run_at' => '06:00'],\n 'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example.test', 'auth' => ['type' => 'bearer', 'token' => Env::string('T_TOK')]], 'stream_tags' => ['app' => 'a']],\n 'analysis' => ['api_key' => Env::string('T_K'), 'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'], ],\n 'state' => ['driver' => 'file', 'file' => ['path' => '" . $this->dir . "/state']],\n 'notify' => ['digest' => ['driver' => 'slack', 'webhook' => Env::string('T_HOOK')]],\n 'mcp' => ['allow_dry_run' => true],\n];\n");
        chmod($p, 0644);
        $in = McpHarness::mem();
        $out = McpHarness::mem();
        $err = McpHarness::mem();
        fwrite($in, '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{}}' . "\n" . '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' . "\n");
        rewind($in);
        McpCommand::main(['w', 'mcp', '--config=' . $p], $in, $out, $err, null, null, static fn (): \Watcher\Mcp\DryRunPipeline => new FakePipeline());
        rewind($out);
        $lines = explode("\n", trim((string) stream_get_contents($out)));
        $tools = json_decode($lines[1], true)['result']['tools'];
        $by = [];
        foreach ($tools as $t) {
            $by[$t['name']] = $t;
        }

        return $by;
    }

    /**
     * @param array<string, mixed> $defs
     */
    private static function resolve(mixed $node, array $defs): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            return self::resolve($defs[substr($node['$ref'], strlen('#/$defs/'))], $defs);
        }
        $out = [];
        foreach ($node as $k => $v) {
            if ($k === 'description') {
                continue;
            }
            $out[$k] = self::resolve($v, $defs);
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return json_decode((string) file_get_contents(self::SCHEMA), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testFourteenToolsMatchTheContract(): void
    {
        $schema = self::schema();
        $listed = $this->listed();
        self::assertSame(array_keys($schema['tools']), array_keys($listed), 'tool names and order');
        self::assertCount(14, $listed);
        foreach ($schema['tools'] as $name => $def) {
            $want = self::resolve($def['inputSchema'], $schema['$defs']);
            $got = self::resolve($listed[$name]['inputSchema'], []);
            if (is_array($got) && ($got['properties'] ?? null) === []) {
                unset($got['properties']);
            }
            if (is_array($want) && ($want['properties'] ?? null) === []) {
                unset($want['properties']);
            }
            self::assertEquals($want, $got, $name);
        }
    }

    public function testAnnotationsAndStaticDescriptions(): void
    {
        foreach ($this->listed() as $name => $t) {
            self::assertTrue($t['annotations']['readOnlyHint'], $name);
            self::assertFalse($t['annotations']['destructiveHint'], $name);
            self::assertSame(in_array($name, ['watcher_check', 'watcher_dry_run', 'watcher_rollout_check'], true), $t['annotations']['openWorldHint'], $name);
            self::assertNotSame('', $t['description']);
            self::assertDoesNotMatchRegularExpression('/\$\{|<\?php|\d{4}-\d{2}-\d{2}T/', $t['description']);
        }
    }

    public function testGroupsMatchTheContract(): void
    {
        $schema = self::schema();
        $groups = ['inspect' => [], 'onboarding' => [], 'docs' => []];
        foreach ($schema['tools'] as $name => $def) {
            $groups[$def['group'] ?? 'inspect'][] = $name;
        }
        self::assertCount(6, $groups['inspect']);
        self::assertCount(5, $groups['onboarding']);
        self::assertCount(3, $groups['docs']);
        $reg = [];
        foreach ($this->listed() as $name => $_) {
            $reg[$name] = true;
        }
        foreach ($groups as $names) {
            foreach ($names as $n) {
                self::assertArrayHasKey($n, $reg);
            }
        }
    }

    public function testEverySchemaStringIsTypedOrMarked(): void
    {
        $schema = self::schema();
        $check = function (array $node, string $path) use (&$check): void {
            if (($node['type'] ?? null) === 'string' || (is_array($node['type'] ?? null) && in_array('string', $node['type'], true))) {
                $marked = isset($node['pattern']) || isset($node['enum']) || isset($node['x-text']) || isset($node['x-static']) || isset($node['x-generated']) || isset($node['maxLength']) || isset($node['x-path']) || isset($node['$ref']);
                self::assertTrue($marked, "untyped string at $path");
            }
            foreach ($node as $k => $v) {
                if (is_array($v)) {
                    $check($v, $path . '.' . $k);
                }
            }
        };
        foreach ($schema['tools'] as $name => $def) {
            $check($def['data'] ?? [], $name);
            $check($def['inputSchema'], $name);
        }
        foreach ($schema['$defs'] as $name => $def) {
            $check($def, '$defs.' . $name);
        }
    }

    public function testNoInputPropertyCanHoldACredential(): void
    {
        $bad = '/^(token|api_?key|webhook|password|secret|bot_token|credential|dsn)$/i';
        $walk = function (mixed $n) use (&$walk, $bad): void {
            if (!is_array($n)) {
                return;
            }
            foreach ($n as $k => $v) {
                if ($k === 'properties' && is_array($v)) {
                    foreach (array_keys($v) as $prop) {
                        self::assertDoesNotMatchRegularExpression($bad, (string) $prop);
                    }
                }
                $walk($v);
            }
        };
        foreach ($this->listed() as $t) {
            $walk($t['inputSchema']);
        }
        self::assertSame(Server::PROTOCOL_VERSIONS[0], '2025-11-25');
    }
}
