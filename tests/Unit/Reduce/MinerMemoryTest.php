<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\Reducer;
use Watcher\Reduce\TemplateMiner;
use Watcher\Source\LogEvent;

/** A4-02 / A4-07: the miner's memory and CPU are bounded per message and per byte, and one bad event never aborts a run. */
final class MinerMemoryTest extends TestCase
{
    private static function ev(string $message = 'x'): LogEvent
    {
        return new LogEvent(new DateTimeImmutable('2026-01-01'), Level::Error, $message, null, ['a.php:1'], [], []);
    }

    public function testATokenCapKeepsAtMostSixtyFourTokensPerStoredMessage(): void
    {
        $m = new TemplateMiner(1);
        $long = array_map(static fn (int $i): string => "t$i", range(1, 300));
        $m->add(self::ev(), $long, 'fp');

        $template = $m->template('fp');
        $this->assertNotNull($template);
        $this->assertLessThanOrEqual(TemplateMiner::MAX_TOKENS + 1, count(explode(' ', $template)));
        $this->assertStringStartsWith('t1 t2 t3', $template);
    }

    public function testMessagesThatDifferOnlyPastTheCapShareOneStoredMessage(): void
    {
        $m = new TemplateMiner(1);
        for ($i = 0; $i < 100; ++$i) {
            $m->add(self::ev(), [...array_map(static fn (int $k): string => "t$k", range(1, 64)), "tail$i", 'z'], "fp$i");
        }
        $this->assertSame(1, $m->stats()->distinctMessages);
    }

    public function testTheByteBudgetWithholdsGroupsDeterministically(): void
    {
        $m = new TemplateMiner(1, memoryBudgetBytes: 2_000);
        for ($i = 0; $i < 50; ++$i) {
            $m->add(self::ev(), ["alpha$i", "beta$i", "gamma$i"], "fp$i");
        }
        $s = $m->stats();
        $this->assertTrue($s->truncated());
        $this->assertGreaterThan(0, $s->droppedMessages);
        $this->assertLessThan(50, $s->distinctMessages);
        $this->assertSame('alpha0 beta0 gamma0', $m->template('fp0'));
        $this->assertNull($m->template('fp49'));
    }

    public function testTheComparisonBudgetCountsTokenOperationsNotComparisons(): void
    {
        // Two long unrelated messages: one comparison costs 20 token operations, over a budget of 10.
        $m = new TemplateMiner(1, comparisonBudget: 10);
        $m->add(self::ev(), array_map(static fn (int $i): string => "a$i", range(1, 20)), 'fp1');
        $m->add(self::ev(), array_map(static fn (int $i): string => "b$i", range(1, 20)), 'fp2');
        $m->add(self::ev(), array_map(static fn (int $i): string => "c$i", range(1, 20)), 'fp3');

        $this->assertTrue($m->stats()->budgetExhausted);
        $this->assertNull($m->template('fp3'));
    }

    public function testAnEventTheNormalizerCannotScanIsMaskedAndCountedNotFatal(): void
    {
        $old = ini_set('pcre.backtrack_limit', '5');
        $oldJit = ini_set('pcre.jit', '0');
        try {
            $r = new Reducer(new Normalizer([], []), 1);
            $groups = $r->reduce([self::ev('user 123 failed ' . str_repeat('abc ', 60)), self::ev('x'), self::ev('x')]);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $old);
            ini_set('pcre.jit', (string) $oldJit);
        }
        $this->assertSame(1, $r->unscannableEvents());
        $this->assertSame(3, array_sum(array_map(static fn ($g): int => $g->count, $groups)));
    }
}
