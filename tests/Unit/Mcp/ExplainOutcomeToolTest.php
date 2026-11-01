<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Watcher\Mcp\Tools\ExplainOutcomeTool;

/** FR-084, T062: `watcher_explain_outcome` answers from the outcome registry only. */
final class ExplainOutcomeToolTest extends TestCase
{
    /** @return array<string, mixed> */
    private function call(string $code): array
    {
        return (new ExplainOutcomeTool())->call(['code' => $code]);
    }

    public function testTheCommonOutcomesAreExplained(): void
    {
        foreach (['disabled', 'skipped_done', 'baselined', 'zero_events', 'lock_lost', 'analysis_refused', 'state_unavailable'] as $code) {
            $r = $this->call($code);
            self::assertTrue($r['found'], $code);
            self::assertSame($code, $r['code']);
            self::assertContains($r['severity'], ['info', 'warn', 'error'], $code);
            self::assertNotSame('', $r['meaning'], $code);
        }
    }

    public function testAFamilyResolvesForItsVariants(): void
    {
        $r = $this->call('truncated:deadline');

        self::assertTrue($r['found']);
        self::assertSame('truncated:deadline', $r['code']);
    }

    public function testAnUnregisteredCodeIsNotFound(): void
    {
        self::assertSame(['found' => false], $this->call('no_such_code'));
    }

    public function testWithoutACodeTheListIsBoundedAndHoldsNoProse(): void
    {
        $r = (new ExplainOutcomeTool())->call([]);

        self::assertNotEmpty($r['codes']);
        self::assertLessThanOrEqual(120, count($r['codes']));
        foreach ($r['codes'] as $entry) {
            self::assertSame(['code', 'kind', 'severity'], array_keys($entry));
        }
    }
}
