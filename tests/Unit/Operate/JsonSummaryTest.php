<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Usage;
use Watcher\Operate\JsonSummary;
use Watcher\Operate\RunStats;
use Watcher\Operate\RunSummaryData;
use Watcher\RunOutcome;

final class JsonSummaryTest extends TestCase
{
    private const FIELDS = [
        'schema', 'service', 'outcome', 'reason', 'window_start', 'window_end', 'duration_ms', 'events_read', 'groups',
        'candidates', 'findings', 'calls', 'tokens_in', 'tokens_out', 'tokens_cached', 'cost_usd', 'today_usd',
        'release', 'version', 'dry_run', 'state_unreadable', 'unscannable_events', 'oversized_lines', 'overflowed_events',
        'pattern_failures', 'read_quality',
    ];

    /** The names under `read_quality`, fixed: a consumer can rely on every one of them being there. */
    private const READ_QUALITY = [
        'unknown_levels', 'orphan_trace_lines', 'message_tails_dropped', 'truncated_lines', 'truncated_structured', 'regex_failures', 'pages_shrunk',
    ];

    /** @return array<string, mixed> */
    private function decode(string $line): array
    {
        self::assertStringEndsNotWith("\n", $line);
        self::assertStringNotContainsString("\n", $line);

        return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testReportedRunHasEveryField(): void
    {
        $stats = new RunStats();
        $stats->eventsRead = 1200;
        $stats->groups = 14;
        $stats->candidates = 5;
        $stats->findings = 2;
        $stats->durationMs = 4300;
        $stats->addUsage('triage', new Usage('m', 100, 10, 40, 0, 0.01));
        $data = new RunSummaryData(
            RunOutcome::Reported,
            'billing',
            null,
            new DateTimeImmutable('2026-10-24T08:00:00+00:00'),
            new DateTimeImmutable('2026-10-24T09:00:00+00:00'),
            $stats,
            0.25,
            'v1.2.3',
            true,
        );

        $j = $this->decode((new JsonSummary())->render($data));

        self::assertSame(self::FIELDS, array_keys($j));
        self::assertSame(1, $j['schema']);
        self::assertSame(['billing', 'reported', null], [$j['service'], $j['outcome'], $j['reason']]);
        self::assertSame('2026-10-24T08:00:00+00:00', $j['window_start']);
        self::assertSame([1200, 14, 5, 2, 4300], [$j['events_read'], $j['groups'], $j['candidates'], $j['findings'], $j['duration_ms']]);
        self::assertSame(['triage' => 1, 'analyze' => 0, 'escalate' => 0], $j['calls']);
        self::assertSame([100, 10, 40], [$j['tokens_in'], $j['tokens_out'], $j['tokens_cached']]);
        self::assertSame([0.01, 0.25, 'v1.2.3', true], [$j['cost_usd'], $j['today_usd'], $j['release'], $j['dry_run']]);
        self::assertIsString($j['version']);
        self::assertNotSame('', $j['version']);
    }

    public function testTheHealthCountersAreAlwaysThereWithStableNames(): void
    {
        $stats = new RunStats();
        $stats->stateUnreadable = 3;
        $stats->unscannableEvents = 4;
        $stats->oversizedLines = 5;
        $stats->overflowedEvents = 6;
        $stats->patternFailures = 7;
        $stats->readQuality = ['unknown_levels' => 8, 'pages_shrunk' => 9, 'not_a_known_key' => 99];

        $j = $this->decode((new JsonSummary())->render(new RunSummaryData(RunOutcome::Reported, 's', null, null, null, $stats)));

        self::assertSame([3, 4, 5, 6, 7], [$j['state_unreadable'], $j['unscannable_events'], $j['oversized_lines'], $j['overflowed_events'], $j['pattern_failures']]);
        self::assertSame(self::READ_QUALITY, array_keys($j['read_quality']));
        self::assertSame(8, $j['read_quality']['unknown_levels']);
        self::assertSame(9, $j['read_quality']['pages_shrunk']);
        self::assertSame(0, $j['read_quality']['regex_failures'], 'a counter the source did not report is 0, not missing');

        $none = $this->decode((new JsonSummary())->render(new RunSummaryData(RunOutcome::Failed, 's')));
        self::assertSame([0, 0, 0, 0, 0], [$none['state_unreadable'], $none['unscannable_events'], $none['oversized_lines'], $none['overflowed_events'], $none['pattern_failures']]);
        self::assertSame(array_fill_keys(self::READ_QUALITY, 0), $none['read_quality']);
    }

    public function testInvalidConfigAndSkippedCarryNullsForUnknownValues(): void
    {
        $j = $this->decode((new JsonSummary())->render(new RunSummaryData(RunOutcome::InvalidConfig, null, 'invalid config')));

        self::assertSame(self::FIELDS, array_keys($j));
        self::assertNull($j['service']);
        self::assertNull($j['window_start']);
        self::assertNull($j['events_read']);
        self::assertNull($j['calls']);
        self::assertNull($j['duration_ms']);
        self::assertNull($j['today_usd']);
        self::assertSame([0, 0, 0, 0.0, false], [$j['tokens_in'], $j['tokens_out'], $j['tokens_cached'], $j['cost_usd'], $j['dry_run']]);
        self::assertSame('invalid config', $j['reason']);

        $skipped = $this->decode((new JsonSummary())->render(new RunSummaryData(RunOutcome::SkippedNotDue, 'svc')));
        self::assertSame('skipped_not_due', $skipped['outcome']);
    }

    public function testReasonThatCouldCarryDataIsReplacedByACode(): void
    {
        foreach (['user anna@example.com failed', "two\nlines", str_repeat('x', 300), 'sk-ant-api03-abcdefghijklmnopqrstuv', 'path /var/log/x'] as $bad) {
            $j = $this->decode((new JsonSummary())->render(new RunSummaryData(RunOutcome::Failed, 's', $bad)));
            self::assertSame('unspecified', $j['reason'], $bad);
        }
        $ok = $this->decode((new JsonSummary())->render(new RunSummaryData(RunOutcome::Failed, 's', 'internal error (RuntimeException)')));
        self::assertSame('internal error (RuntimeException)', $ok['reason']);
    }

    public function testVersionIsAStringEvenWhenNotInstalledThroughComposer(): void
    {
        self::assertSame('unknown', JsonSummary::packageVersion('no/such-package'));
    }
}
