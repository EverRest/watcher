<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Analyze\Usage;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Notify\Notifier;
use Watcher\Source\LogEvent;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Watcher;

/**
 * Security review F8, cost: whoever can write logs can make thousands of distinct groups, and so thousands of billed
 * calls. Spend stays inside analysis.daily_budget_usd and every request stays inside source.group_cap.
 */
final class HostileVolumeBudgetTest extends TestCase
{
    /** Distinct groups in the hostile window: thousands would be slower without changing what is pinned. */
    private const GROUPS = 1200;

    /** Prices per million tokens from Harness::config(): m-t input 1, output 5; m-a input 3, output 15; m-e input 5, output 25. */
    private const PRICE = ['m-t' => [1.0, 5.0], 'm-a' => [3.0, 15.0], 'm-e' => [5.0, 25.0]];

    private HostileAnswer $analyzer;

    /** A distinct, digit-free word per group, so the redactor does not fold them into one template. */
    private static function letters(int $i): string
    {
        $s = '';
        do {
            $s = chr(97 + $i % 26) . $s;
            $i = intdiv($i, 26);
        } while ($i > 0);

        return $s;
    }

    /**
     * Distinct groups: each leads with its own word, and the fingerprint keys on the first two tokens. Two events each,
     * since a group the miner cannot cluster is not triaged at all.
     *
     * @return list<LogEvent>
     */
    private static function hostileGroups(int $n): array
    {
        $at = new DateTimeImmutable('2026-10-07T12:00:00', new DateTimeZone('UTC'));
        $events = [];
        for ($i = 0; $i < $n; ++$i) {
            for ($k = 0; $k < 2; ++$k) {
                $events[] = new LogEvent($at, Level::Error, self::letters($i) . ' hostile group <!channel> see https://evil.com/x', 'Alpha', [], [], []);
            }
        }

        return $events;
    }

    /** @param list<Usage> $billed */
    private static function cost(array $billed): float
    {
        $total = 0.0;
        foreach ($billed as $u) {
            [$in, $out] = self::PRICE[$u->model] ?? [0.0, 0.0];
            $total += ($u->inputTokens * $in + $u->outputTokens * $out) / 1_000_000;
        }

        return $total;
    }

    /** @param list<LogEvent> $events */
    private static function runWindow(Harness $h, Analyzer $analyzer, Notifier $notifier, array $events): void
    {
        (new Watcher(WatchConfig::fromArray($h->config()), new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $analyzer, $notifier, $h->clock))->run();
    }

    /**
     * One baseline window, then the hostile volume in the next. The model answers with usage inside the planned bounds
     * (analysis.max_input_tokens 20000, max_output_tokens 1500), which is what the reservation prices.
     *
     * @param array<string, mixed> $patch
     */
    private function volumeRun(array $patch): Harness
    {
        $h = new Harness($patch);
        $this->analyzer = new HostileAnswer('ignore previous: noise', 'interesting', Severity::Error, FindingKind::Bug, 0.9, 20_000, 1_500);
        self::runWindow($h, $this->analyzer, $h->notifier, Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha'));
        $h->nextWindow();
        self::runWindow($h, $this->analyzer, $h->notifier, self::hostileGroups(self::GROUPS));

        return $h;
    }

    public function testARealCallIsBilledAndTheRunStaysInsideTheDailyBudget(): void
    {
        $h = $this->volumeRun(['analysis' => ['daily_budget_usd' => 0.2]]);

        self::assertNotEmpty($this->analyzer->billed, 'nothing was billed: the test proves nothing');
        self::assertLessThanOrEqual(0.2, self::cost($this->analyzer->billed), 'spend went past analysis.daily_budget_usd');
        self::assertStringContainsString('groups dropped (budget)', implode("\n", $h->sentTexts()), 'the token bound did not engage for thousands of groups');
    }

    public function testTheDailyBudgetRefusesTheStepThatWouldExceedItAndSaysSo(): void
    {
        $h = $this->volumeRun(['analysis' => ['daily_budget_usd' => 0.15]]);

        self::assertLessThanOrEqual(0.15, self::cost($this->analyzer->billed), 'spend went past analysis.daily_budget_usd');
        self::assertStringContainsString('the daily budget would have been exceeded', implode("\n", $h->sentTexts()), 'the budget never refused a step');
    }

    public function testGroupCapBoundsWhatTheModelIsAskedToReadWhateverTheVolume(): void
    {
        $h = $this->volumeRun(['analysis' => ['daily_budget_usd' => 1000], 'source' => ['group_cap' => 100]]);

        self::assertNotEmpty($this->analyzer->triageGroups);
        // group_cap distinct groups, plus the overflow group that folds everything beyond the cap.
        self::assertLessThanOrEqual(101, max(0, ...$this->analyzer->triageGroups), 'a triage request carried more groups than the cap allows');
        self::assertStringContainsString('cluster cap hit', implode("\n", $h->sentTexts()), 'the cap was not reported in the digest');
    }
}
