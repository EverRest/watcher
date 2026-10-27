<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\DocsIndex;
use Watcher\Mcp\InvalidParams;
use Watcher\Mcp\PromptCatalog;
use Watcher\Mcp\SecretGuard;

final class PromptCatalogTest extends TestCase
{
    private const ALL = ['watcher_status', 'watcher_check', 'watcher_latest_report', 'watcher_setup_plan', 'watcher_validate_config', 'watcher_generate_config', 'watcher_first_day', 'watcher_rollout_check', 'watcher_search_docs', 'watcher_explain_config', 'watcher_explain_outcome'];

    /** @param list<string> $tools */
    private function catalog(array $tools = self::ALL): PromptCatalog
    {
        return new PromptCatalog(new DocsIndex(__DIR__ . '/../../..'), $tools, new SecretGuard());
    }

    public function testFourPromptsWhenTheirToolsAreEnabledAndFilteringOtherwise(): void
    {
        self::assertSame(['onboard-new-service', 'why-is-the-digest-quiet', 'rotate-credentials'], array_column($this->catalog()->prompts(), 'name'));
        self::assertSame([], $this->catalog(['watcher_search_docs'])->prompts());
        $this->expectException(InvalidParams::class);
        $this->catalog(['watcher_search_docs'])->prompt('onboard-new-service', []);
    }

    public function testEveryTextHasTheStandingSentenceAndNamesOnlyEnabledTools(): void
    {
        $c = $this->catalog();
        foreach ([['onboard-new-service', []], ['onboard-new-service', ['host' => 'plain_php', 'source' => 'datadog', 'notifier' => 'telegram', 'state' => 'file']], ['why-is-the-digest-quiet', []], ['rotate-credentials', ['which' => 'loki']]] as [$name, $args]) {
            $r = $c->prompt($name, $args);
            $text = $r['messages'][0]['content']['text'];
            self::assertSame('user', $r['messages'][0]['role']);
            self::assertStringContainsString(PromptCatalog::STANDING, $text);
            preg_match_all('/watcher_[a-z_]+/', $text, $m);
            foreach ($m[0] as $tool) {
                self::assertContains($tool, self::ALL, "$name names $tool");
            }
        }
    }

    public function testDefaultsAreSubstitutedAndUnknownEnumerationsAreInvalidParams(): void
    {
        self::assertStringContainsString('laravel host, loki logs, slack notifications, redis state', $this->catalog()->prompt('onboard-new-service', [])['messages'][0]['content']['text']);
        foreach ([['onboard-new-service', ['host' => 'windows']], ['rotate-credentials', []], ['rotate-credentials', ['which' => 'vault']], ['why-is-the-digest-quiet', ['x' => 'y']], ['nope', []]] as [$n, $a]) {
            try {
                $this->catalog()->prompt($n, $a);
                self::fail($n);
            } catch (InvalidParams $e) {
                self::assertStringNotContainsString('vault', $e->getMessage());
            }
        }
    }

    public function testASecretShapedArgumentGetsTheFixedMessage(): void
    {
        $secret = 'xoxb-' . '123456789012-1234567890123-abcdefghijklmnopqrstuvwx';
        try {
            $this->catalog()->prompt('rotate-credentials', ['which' => $secret]);
            self::fail();
        } catch (InvalidParams $e) {
            self::assertSame('Give the name of the environment variable, not its value.', $e->getMessage());
        }
    }

    public function testResourcesAndPromptsAreRoutedThroughTheServerOnlyWithTheDocsGroup(): void
    {
        $msgs = [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list'], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/templates/list'], ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/read', 'params' => ['uri' => 'watcher-docs://../.env']], ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'prompts/list'], ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'prompts/get', 'params' => ['name' => 'why-is-the-digest-quiet']], ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'resources/subscribe'], ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'resources/read', 'params' => ['uri' => 5]]];
        $r = (new McpHarness())->run($msgs, [StubTool::plain('watcher_explain_config', 'docs')], ['docs'], false, $this->catalog());
        self::assertNotSame([], $r[0]['result']['resources']);
        self::assertSame(['resourceTemplates' => []], $r[1]['result']);
        self::assertSame(-32602, $r[2]['error']['code']);
        self::assertSame('resource_not_found', $r[2]['error']['message']);
        self::assertArrayNotHasKey('nextCursor', $r[0]['result']);
        self::assertArrayHasKey('prompts', $r[3]['result']);
        self::assertSame('user', $r[4]['result']['messages'][0]['role']);
        self::assertSame(-32601, $r[5]['error']['code']);
        self::assertSame(-32602, $r[6]['error']['code']);

        $off = (new McpHarness())->run([$msgs[0], $msgs[3]], [StubTool::plain()], ['inspect']);
        self::assertSame(-32601, $off[0]['error']['code']);
        self::assertSame(-32601, $off[1]['error']['code']);
    }

    public function testInitializeDeclaresResourcesAndPromptsOnlyWithDocs(): void
    {
        $h = new McpHarness();
        $init = [['jsonrpc' => '2.0', 'id' => 9, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]];
        $on = $h->run($init, [StubTool::plain('watcher_explain_config', 'docs')], ['docs'], false, $this->catalog());
        self::assertSame(['resources' => ['subscribe' => false, 'listChanged' => false], 'prompts' => ['listChanged' => false]], array_diff_key($on[0]['result']['capabilities'], ['tools' => 1]));
        $off = $h->run($init, [StubTool::plain()]);
        self::assertSame(['tools' => ['listChanged' => false]], $off[0]['result']['capabilities']);
    }
}
