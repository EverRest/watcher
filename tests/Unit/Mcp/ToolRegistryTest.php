<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    /** @return list<\Watcher\Mcp\Tool> */
    private function tools(): array
    {
        $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20], 'name' => ['type' => 'string', 'pattern' => '^[a-z]{1,10}$']]];

        return [
            new StubTool('watcher_latest_report', 'inspect', $schema, static fn (array $a): array => ['got' => $a]),
            new StubTool('watcher_dry_run', 'inspect', $schema, static fn (array $a): array => []),
            new StubTool('watcher_first_day', 'onboarding', $schema, static fn (array $a): array => ['got' => $a]),
            new StubTool('watcher_explain_config', 'docs', $schema, static fn (array $a): array => []),
        ];
    }

    /**
     * @param list<string> $groups
     * @return list<string>
     */
    private function listed(array $groups, bool $dry = false): array
    {
        $r = (new McpHarness())->run([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']], $this->tools(), $groups, $dry);

        return array_values(array_map(static fn (array $t): string => $t['name'], $r[0]['result']['tools']));
    }

    public function testActionsAreListedOnlyWhenEnabledAndMarkedAsWrites(): void
    {
        $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => []];
        $tools = [new StubTool('watcher_health_check', 'actions', $schema, static fn (array $a): array => [])];

        self::assertSame([], (new McpHarness())->run([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']], $tools, ['inspect'])[0]['result']['tools']);

        $listed = (new McpHarness())->run([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']], $tools, ['actions'])[0]['result']['tools'];
        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false], $listed[0]['annotations']);
    }

    public function testGroupsAndDryRunSwitch(): void
    {
        self::assertSame(['watcher_latest_report', 'watcher_first_day', 'watcher_explain_config'], $this->listed(['inspect', 'onboarding', 'docs']));
        self::assertSame(['watcher_latest_report', 'watcher_dry_run', 'watcher_first_day', 'watcher_explain_config'], $this->listed(['inspect', 'onboarding', 'docs'], true));
        self::assertSame(['watcher_latest_report'], $this->listed(['inspect']));
        self::assertSame(['watcher_first_day', 'watcher_explain_config'], $this->listed(['onboarding', 'docs']));
    }

    public function testATooloOfAnOffGroupIsInvalidParamsOnCall(): void
    {
        $r = (new McpHarness())->run([McpHarness::call('watcher_first_day')], $this->tools(), ['inspect']);
        self::assertSame(-32602, $r[0]['error']['code']);
        $r = (new McpHarness())->run([McpHarness::call('watcher_dry_run')], $this->tools(), ['inspect']);
        self::assertSame(-32602, $r[0]['error']['code']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalid(): array
    {
        return [
            'unknown property' => [['bogus' => 1], 'bogus'],
            'wrong type' => [['limit' => '5'], 'limit'],
            'limit 0' => [['limit' => 0], 'limit'],
            'limit 21' => [['limit' => 21], 'limit'],
            'pattern' => [['name' => 'UPPER-VALUE-123'], 'name'],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @dataProvider invalid
     */
    public function testInvalidArgumentsNameThePropertyAndNeverTheValue(array $args, string $property): void
    {
        $r = (new McpHarness())->run([McpHarness::call('watcher_latest_report', $args)], $this->tools());
        self::assertSame(-32602, $r[0]['error']['code']);
        self::assertSame('invalid params: ' . $property, $r[0]['error']['message']);
        self::assertStringNotContainsString('UPPER-VALUE', json_encode($r) ?: '');
    }

    public function testAValidCallReachesTheTool(): void
    {
        $r = (new McpHarness())->run([McpHarness::call('watcher_latest_report', ['limit' => 3])], $this->tools());
        self::assertSame(['got' => ['limit' => 3]], McpHarness::envelope($r[0])['data']);
        self::assertFalse($r[0]['result']['isError']);
    }

    public function testASecretInAnOnboardingToolIsRefusedBeforeTheSchemaAndNothingIsEchoed(): void
    {
        $secret = 'xoxb-' . '123456789012-abcdefghijklmnop';
        $h = new McpHarness();
        $r = $h->run([McpHarness::call('watcher_first_day', ['name' => $secret])], $this->tools());
        $env = McpHarness::envelope($r[0]);
        self::assertTrue($r[0]['result']['isError']);
        self::assertSame('secret_refused', $env['error']);
        self::assertSame('Give the name of the environment variable, not its value.', $env['message']);
        self::assertStringNotContainsString($secret, $h->raw . $h->err);
        self::assertStringContainsString('SecretRefused in watcher_first_day', $h->err);
    }

    public function testAToolThatThrowsIsAnInternalErrorWithTheClassOnStderrOnly(): void
    {
        $t = new StubTool('watcher_status', 'inspect', ['type' => 'object', 'additionalProperties' => false, 'properties' => []], static function (): array {
            throw new \RuntimeException('host=secret.internal password=hunter2');
        });
        $h = new McpHarness();
        $r = $h->run([McpHarness::call('watcher_status')], [$t]);
        self::assertSame('internal_error', McpHarness::envelope($r[0])['error']);
        self::assertStringNotContainsString('hunter2', $h->raw . $h->err);
        self::assertStringContainsString('RuntimeException', $h->err);
    }
}
