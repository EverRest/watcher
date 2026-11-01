<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Mcp\McpSettings;

/** FR-040, T018: the `mcp` section. The dry run is off by default, strictly boolean, and no credential key exists in it. */
final class McpConfigTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('WATCHER_MCP_ALLOW_DRY_RUN');
    }

    protected function tearDown(): void
    {
        putenv('WATCHER_MCP_ALLOW_DRY_RUN');
    }

    public function testTheDryRunIsOffByDefault(): void
    {
        self::assertFalse(McpSettings::fromArray([])->allowDryRun);
    }

    public function testTheEnvironmentVariableTurnsItOn(): void
    {
        putenv('WATCHER_MCP_ALLOW_DRY_RUN=true');

        self::assertTrue(McpSettings::fromArray([])->allowDryRun);
    }

    public function testTheSettingIsStrictlyBoolean(): void
    {
        foreach (['true', 1, 'yes'] as $value) {
            try {
                McpSettings::fromArray(['allow_dry_run' => $value]);
                self::fail('accepted ' . var_export($value, true));
            } catch (InvalidConfig $e) {
                self::assertSame('mcp.allow_dry_run must be true or false', $e->getMessage());
            }
        }
        self::assertTrue(McpSettings::fromArray(['allow_dry_run' => true])->allowDryRun);
    }

    public function testAnyOtherKeyIsRejectedNamingItsPath(): void
    {
        foreach (['token', 'webhook', 'api_key', 'password'] as $key) {
            try {
                McpSettings::fromArray([$key => 'value']);
                self::fail("accepted mcp.$key");
            } catch (InvalidConfig $e) {
                self::assertSame("Unknown configuration key: mcp.$key", $e->getMessage());
                self::assertStringNotContainsString('value', $e->getMessage());
            }
        }
    }
}
