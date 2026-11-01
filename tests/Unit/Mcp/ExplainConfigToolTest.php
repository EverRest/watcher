<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Config\KeyCatalog;
use Watcher\Mcp\Tools\ExplainConfigTool;

/** FR-083, T062: `watcher_explain_config` answers from the key catalog only. */
final class ExplainConfigToolTest extends TestCase
{
    /** @return array<string, mixed> */
    private function call(string $key): array
    {
        return (new ExplainConfigTool())->call(['key' => $key]);
    }

    public function testEveryCatalogTypeHasAKeyThatIsExplained(): void
    {
        $byType = [];
        foreach (KeyCatalog::paths() as $path) {
            $entry = KeyCatalog::find($path);
            if ($entry !== null) {
                $byType[$entry['type']] ??= $path;
            }
        }
        self::assertNotEmpty($byType);
        foreach ($byType as $type => $path) {
            $r = $this->call($path);
            self::assertTrue($r['found'], $path);
            self::assertSame($type, $r['type'], $path);
        }
    }

    public function testADefaultIsRenderedFromTheCatalog(): void
    {
        $r = $this->call('selection.growth_factor');

        self::assertTrue($r['found']);
        $entry = KeyCatalog::find('selection.growth_factor');
        self::assertNotNull($entry);
        self::assertSame($entry['default'], $r['default']);
    }

    public function testAWildcardMapEntryIsExplained(): void
    {
        self::assertTrue($this->call('source.stream_tags.app')['found']);
    }

    public function testCredentialKeysAreFlaggedAsSecrets(): void
    {
        foreach (['source.loki.auth.token', 'notify.digest.webhook'] as $key) {
            self::assertTrue($this->call($key)['secret'], $key);
        }
        self::assertFalse($this->call('selection.growth_factor')['secret']);
    }

    public function testAnUnknownKeyGivesCloseSuggestionsAndDoesNotEchoTheInput(): void
    {
        $r = $this->call('selection.min_levl');

        self::assertFalse($r['found']);
        self::assertContains('selection.min_level', $r['did_you_mean']);
        self::assertLessThanOrEqual(3, count($r['did_you_mean']));
        self::assertNotContains('selection.min_levl', $r['did_you_mean']);
        self::assertStringNotContainsString('min_levl', json_encode($r, JSON_THROW_ON_ERROR));
    }

    public function testASecretShapedKeyIsRefusedBeforeTheCatalogIsRead(): void
    {
        $secret = 'sk-' . str_repeat('a1', 20);
        $h = new McpHarness();
        $r = $h->run([McpHarness::call('watcher_explain_config', ['key' => $secret])], [new ExplainConfigTool()], ['docs']);

        self::assertTrue($r[0]['result']['isError']);
        self::assertSame('secret_refused', McpHarness::envelope($r[0])['error']);
        self::assertStringNotContainsString($secret, $h->raw);
    }
}
