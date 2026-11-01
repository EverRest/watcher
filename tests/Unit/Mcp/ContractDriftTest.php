<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\DocsAllowlist;
use Watcher\Mcp\Envelope;
use Watcher\Mcp\McpCommand;

/**
 * FR-085, T064: contracts/tools.md and the code name the same tools, resources, prompts and error codes. The `actions`
 * group is not in tools.md or tools.schema.json (it is documented in docs/mcp.md), so the tool headings are compared
 * with the other groups and the actions are checked against docs/mcp.md instead.
 */
final class ContractDriftTest extends TestCase
{
    private const CONTRACT = __DIR__ . '/../../../specs/015-mcp-readonly/contracts/tools.md';
    private const DOCS = __DIR__ . '/../../../docs/mcp.md';
    private const ACTIONS = ['watcher_run_digest', 'watcher_health_check', 'watcher_test_message'];
    private const PENDING_OWNER_DECISION = ['cost-too-high'];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/mcp-contract-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/state', 0700, true);
        file_put_contents($this->dir . '/artisan', "<?php\n");
        putenv('T_K=k');
        putenv('T_HOOK=https://hooks.slack.com/services/T0/B0/xxxx');
        putenv('T_TOK=tok');
    }

    protected function tearDown(): void
    {
        foreach (['T_K', 'T_HOOK', 'T_TOK'] as $k) {
            putenv($k);
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /**
     * Runs the server with every group and the dry run on, and returns the decoded replies to the given requests.
     *
     * @return list<array<string, mixed>>
     */
    private function reply(string ...$methods): array
    {
        $p = $this->dir . '/c.php';
        file_put_contents($p, "<?php\nuse Watcher\\Config\\Env;\nreturn [\n 'enabled' => true,\n 'service' => 'orders-api',\n 'schedule' => ['run_at' => '06:00'],\n 'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.example.test', 'auth' => ['type' => 'bearer', 'token' => Env::string('T_TOK')]], 'stream_tags' => ['app' => 'a']],\n 'analysis' => ['api_keys' => ['gemini' => Env::string('T_K')], 'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3']],\n 'state' => ['driver' => 'file', 'file' => ['path' => '" . $this->dir . "/state']],\n 'notify' => ['digest' => ['driver' => 'slack', 'webhook' => Env::string('T_HOOK')]],\n 'mcp' => ['allow_dry_run' => true, 'groups' => ['inspect', 'onboarding', 'docs', 'actions'], 'artisan' => '" . $this->dir . "/artisan'],\n];\n");
        $in = McpHarness::mem();
        $out = McpHarness::mem();
        $err = McpHarness::mem();
        fwrite($in, '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{}}' . "\n");
        foreach (array_values($methods) as $i => $method) {
            fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => $i + 1, 'method' => $method, 'params' => []], JSON_THROW_ON_ERROR) . "\n");
        }
        rewind($in);
        McpCommand::main(['w', 'mcp', '--config=' . $p], $in, $out, $err, null, null, static fn (): \Watcher\Mcp\DryRunPipeline => new FakePipeline());
        rewind($out);
        $lines = explode("\n", trim((string) stream_get_contents($out)));

        return array_map(static fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), array_slice($lines, 1));
    }

    private static function contract(): string
    {
        return (string) file_get_contents(self::CONTRACT);
    }

    public function testToolHeadingsOfTheContractEqualTheRegistryOutsideActions(): void
    {
        preg_match_all('/^## (watcher_\w+)/m', self::contract(), $m);
        $listed = array_map(static fn (array $t): string => $t['name'], $this->reply('tools/list')[0]['result']['tools']);
        $listed = array_values(array_diff($listed, self::ACTIONS));

        self::assertSame($listed, $m[1]);
        self::assertCount(14, $listed);
    }

    public function testTheActionsAreListedAndDocumented(): void
    {
        $listed = array_map(static fn (array $t): string => $t['name'], $this->reply('tools/list')[0]['result']['tools']);
        $docs = (string) file_get_contents(self::DOCS);
        foreach (self::ACTIONS as $name) {
            self::assertContains($name, $listed);
            self::assertStringContainsString('`' . $name . '`', $docs, $name);
        }
    }

    public function testTheResourceListOfTheContractEqualsTheAllowlist(): void
    {
        $section = self::section('## Resources (group docs)', '## Prompts');
        preg_match_all('/`((?:README|CHANGELOG|SECURITY)\.md|(?:docs|config)\/[^`\s]+)`/', $section, $m);
        $documented = array_values(array_unique($m[1]));
        $allowed = array_keys(DocsAllowlist::FILES);

        sort($documented);
        sort($allowed);
        self::assertSame($allowed, $documented);
    }

    public function testThePromptTableEqualsThePromptCatalog(): void
    {
        $section = self::section('## Prompts (group docs)', '`prompts/get` result');
        preg_match_all('/^\| `([a-z-]+)` \|/m', $section, $m);

        $served = array_map(static fn (array $p): string => $p['name'], $this->reply('prompts/list')[0]['result']['prompts']);

        // OWNER DECISION: tools.md still lists `cost-too-high`, but spec 017 removed cost accounting and the code does not serve it.
        self::assertSame(self::PENDING_OWNER_DECISION, array_values(array_diff($m[1], $served)));
        self::assertSame([], array_values(array_diff($served, $m[1])));
    }

    public function testTheClosedErrorCodesOfTheContractEqualTheEnvelope(): void
    {
        $section = self::section('Closed set of codes:', '`file_too_large`');
        preg_match_all('/`([a-z_]+)`/', $section, $m);
        $documented = $m[1];
        $code = array_keys(Envelope::MESSAGES);

        sort($documented);
        sort($code);
        self::assertSame($code, $documented);
    }

    /** The text of the contract from the first line that holds `$from` up to the next `$to`. */
    private static function section(string $from, string $to): string
    {
        $contract = self::contract();
        $start = strpos($contract, $from);
        self::assertNotFalse($start, $from);
        $end = strpos($contract, $to, $start + strlen($from));
        self::assertNotFalse($end, $to);

        return substr($contract, $start, $end + strlen($to) - $start);
    }
}
