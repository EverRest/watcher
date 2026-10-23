<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Budget;
use Watcher\Reduce\ProblemGroup;
use Watcher\Select\Candidate;

final class BudgetTest extends TestCase
{
    public function testEstimateIsUtf8BytesOverThreeRoundedUp(): void
    {
        self::assertSame(0, Budget::estimateTokens(''));
        self::assertSame(1, Budget::estimateTokens('ab'));
        self::assertSame(1, Budget::estimateTokens('abc'));
        self::assertSame(2, Budget::estimateTokens('abcd'));
        self::assertSame(2, Budget::estimateTokens('ёё'), '2 chars = 4 bytes');
    }

    private function cand(int $priority): Candidate
    {
        $group = (new \ReflectionClass(ProblemGroup::class))->newInstanceWithoutConstructor();

        return new Candidate($group, [], $priority);
    }

    /** @param list<Candidate> $cs */
    private static function ser(array $cs): string
    {
        return str_repeat('x', 30 * count($cs)); // 10 tokens each
    }

    public function testNothingDroppedWhenItFits(): void
    {
        $cs = [$this->cand(1), $this->cand(2)];

        $r = Budget::truncate($cs, 20, self::ser(...));

        self::assertSame($cs, $r->kept);
        self::assertSame(0, $r->droppedCount);
    }

    public function testDropsLowestPriorityFirstKeepingOrder(): void
    {
        $a = $this->cand(5);
        $b = $this->cand(1);
        $c = $this->cand(9);
        $d = $this->cand(3);

        $r = Budget::truncate([$a, $b, $c, $d], 20, self::ser(...));

        self::assertSame([$a, $c], $r->kept);
        self::assertSame(2, $r->droppedCount);
    }

    public function testTiesDropLaterOnesAndAreDeterministic(): void
    {
        $a = $this->cand(1);
        $b = $this->cand(1);
        $c = $this->cand(1);

        $r = Budget::truncate([$a, $b, $c], 20, self::ser(...));

        self::assertSame([$a, $b], $r->kept);
        self::assertSame(1, $r->droppedCount);
    }

    public function testEverythingDroppedWhenNothingFits(): void
    {
        $r = Budget::truncate([$this->cand(1)], 5, self::ser(...));

        self::assertSame([], $r->kept);
        self::assertSame(1, $r->droppedCount);
    }

    public function testEmptyInput(): void
    {
        $r = Budget::truncate([], 1, static fn (array $c): string => '');

        self::assertSame([], $r->kept);
        self::assertSame(0, $r->droppedCount);
    }

    public function testSystemPromptCarriesTheStepPromptAndTheServiceContext(): void
    {
        $triage = Budget::systemPrompt('triage', 'orders service, PHP 8');
        $analyze = Budget::systemPrompt('analyze', '');

        self::assertStringContainsString("## Service context\n\norders service, PHP 8", $triage);
        self::assertNotSame($triage, Budget::systemPrompt('analyze', 'orders service, PHP 8'), 'each step has its own prompt');
        self::assertGreaterThan(300, Budget::estimateTokens($analyze), 'the analysis prompt alone is hundreds of tokens');
    }

    public function testTruncateCountsTheFixedPromptOverheadAgainstTheLimit(): void
    {
        $cs = [$this->cand(1), $this->cand(2)]; // 20 tokens together

        self::assertSame(0, Budget::truncate($cs, 25, self::ser(...))->droppedCount);
        self::assertSame(1, Budget::truncate($cs, 25, self::ser(...), 10)->droppedCount, '20 + 10 > 25');
    }
}
