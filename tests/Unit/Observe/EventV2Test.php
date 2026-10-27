<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use PHPUnit\Framework\TestCase;

/** watcher.event.v2 has no removed field and no budget record (contracts/event-v2.md). Red until US3. */
final class EventV2Test extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../../docs/schema/watcher.event.v2.schema.json';
    private const REMOVED = ['cost_usd', 'tokens_in', 'tokens_out', 'tokens_cached', 'run_cost_usd', 'spent_today_usd', 'daily_budget_usd', 'usd_ratio', 'tokens_today', 'daily_token_limit', 'token_ratio', 'triage_only'];

    public function testV2SchemaExistsAndNamesNoRemovedField(): void
    {
        self::assertFileExists(self::SCHEMA);
        $text = (string) file_get_contents(self::SCHEMA);
        foreach (self::REMOVED as $name) {
            self::assertStringNotContainsString('"' . $name . '"', $text, "v2 must not carry $name");
        }
        self::assertStringContainsString('"watcher.event.v2"', $text);
    }

    public function testTheEventSchemaCodeHasNoRemovedField(): void
    {
        $code = (string) file_get_contents(__DIR__ . '/../../../src/Observe/EventSchema.php');
        foreach (self::REMOVED as $name) {
            self::assertStringNotContainsString("'$name'", $code, "EventSchema must not list $name");
        }
    }
}
