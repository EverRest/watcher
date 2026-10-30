<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\RoutingNotifier;
use Watcher\Observe\EventSanitizer;
use Watcher\Observe\ObserveCounters;
use Watcher\Observe\SinkDispatcher;
use Watcher\Operate\JsonSummary;
use Watcher\Operate\RunOptions;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\RunOutcome;
use Watcher\RunResult;
use Watcher\Source\LogSource;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\RecordingEventSink;
use Watcher\Watcher;

/** Spec 013 US1/US4: a run hands its events to the sinks once, and a sink can never change the run. */
final class ObserveTest extends TestCase
{
    private const LOG = ['observe' => ['log' => ['enabled' => true]], 'schedule' => ['run_at' => '06:00'], 'selection' => ['top_n' => 3, 'top_m' => 3]];

    /** @return list<string> n distinct letters-only words, so each becomes its own group */
    private static function words(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; ++$i) {
            $out[] = 'Fault' . strtr((string) $i, '0123456789', 'abcdefghij') . 'Zone';
        }

        return $out;
    }

    /** 40 groups; the first three grew (6 events against 2 in the baseline window), the rest are unchanged, so exactly three reach the model. */
    private static function source(int $groups = 40, int $each = 6, Level $level = Level::Error): LogSource
    {
        $events = [];
        foreach (self::words($groups) as $i => $w) {
            $events = [...$events, ...Harness::events($level, $w . ' broke in a unique way', $i < 3 ? $each : 2, $w)];
        }

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    private static function dispatcher(RecordingEventSink $sink, bool $text = true): SinkDispatcher
    {
        return new SinkDispatcher(['log' => [$sink, $text]], new EventSanitizer(new Redactor(new Normalizer())));
    }

    private static function watcher(Harness $h, LogSource $source, ?SinkDispatcher $sinks, ?ObserveCounters $counters = null, ?RunOptions $options = null, mixed $out = null, ?\Watcher\Notify\Notifier $notifier = null): Watcher
    {
        return new Watcher(WatchConfig::fromArray($h->config()), $source, $h->state, $h->analyzer, $notifier ?? $h->notifier, $h->clock, $h->hostLog, $options, $out, sinks: $sinks, counters: $counters);
    }

    /** A baselined first window, then the window under test. The baseline is Warning: an Error first run would post (FR-035). */
    private static function ready(Harness $h): void
    {
        self::watcher($h, self::source(40, 2, Level::Warning), null)->run();
        $h->nextWindow();
    }

    /** @return list<array<string, mixed>> */
    private static function events(RecordingEventSink $sink): array
    {
        return array_merge([], ...$sink->received);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, int>
     */
    private static function byType(array $events): array
    {
        $counts = array_count_values(array_column($events, 'event_type'));

        return array_combine(array_map('strval', array_keys($counts)), array_values($counts));
    }

    /** @return array<string, mixed> */
    private static function keys(Harness $h): array
    {
        self::assertInstanceOf(InMemoryStateStore::class, $h->state);

        return $h->state->all();
    }

    private static function summaryJson(RunResult $r): string
    {
        self::assertNotNull($r->summaryData);

        return (new JsonSummary())->render($r->summaryData);
    }

    public function testEventCountsForThreeFindings(): void
    {
        $h = new Harness(self::LOG);
        self::ready($h);
        $sink = new RecordingEventSink();

        $r = self::watcher($h, self::source(), self::dispatcher($sink))->run();

        $all = self::events($sink);
        // 37 of the 40 groups were not analysed (top_m = 3): that is the chat's "not analysed" line, so it is an event too.
        $warnings = array_values(array_filter($all, static fn (array $e): bool => $e['event_type'] === 'config_warning'));
        self::assertSame([['analysis_partial', 37]], array_map(static fn (array $e): array => [$e['code'], $e['count']], $warnings));
        $events = array_values(array_filter($all, static fn (array $e): bool => $e['event_type'] !== 'config_warning'));
        self::assertSame(RunOutcome::Reported, $r->outcome);
        self::assertSame(['run' => 1, 'finding' => 3, 'group' => 20, 'heartbeat' => 1], self::byType($events));
        self::assertCount(25, $events);
        self::assertSame(range(1, 26), array_map(static fn (array $e): int => (int) substr((string) strrchr($e['event_id'], '-'), 1), $all));
        self::assertCount(1, array_unique(array_column($events, 'run_id')));
        self::assertSame(40, $events[0]['groups_total']);
    }

    public function testDashboardOnlyModeEmitsEventsAndNoChat(): void
    {
        $h = new Harness(['notify' => ['digest' => ['driver' => 'none']]] + self::LOG);
        $chat = [new FakeNotifier(), new FakeNotifier(), new FakeNotifier()];
        $router = new RoutingNotifier(...$chat);
        self::watcher($h, self::source(40, 2), null, notifier: $router)->run();
        $h->nextWindow();
        $sink = new RecordingEventSink();

        $r = self::watcher($h, self::source(), self::dispatcher($sink), notifier: $router)->run();

        self::assertSame(RunOutcome::Reported, $r->outcome);
        self::assertSame(0, $chat[0]->attempts + $chat[1]->attempts + $chat[2]->attempts);
        self::assertSame(1, self::byType(self::events($sink))['run']);
    }

    public function testDriverNoneMakesSameAnalyzerCallsAsSlack(): void
    {
        $calls = [];
        foreach (['slack', 'none'] as $driver) {
            $h = new Harness(['notify' => ['digest' => $driver === 'none' ? ['driver' => 'none'] : ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x']]] + self::LOG);
            $router = new RoutingNotifier(new FakeNotifier(), new FakeNotifier(), new FakeNotifier());
            self::watcher($h, self::source(40, 2), null, notifier: $router)->run();
            $h->nextWindow();
            self::watcher($h, self::source(), self::dispatcher(new RecordingEventSink()), notifier: $router)->run();
            $calls[$driver] = [count($h->analyzer->triageRequests), count($h->analyzer->requests), count(self::keys($h))];
        }

        self::assertSame($calls['slack'], $calls['none']);
    }

    public function testNoSinksIsByteIdentical(): void
    {
        $plain = new Harness(['schedule' => ['run_at' => '06:00'], 'selection' => ['top_n' => 3, 'top_m' => 3]]);
        self::ready($plain);
        $r = self::watcher($plain, self::source(), null)->run();

        self::assertStringNotContainsString('run_id', self::summaryJson($r));
        self::assertStringNotContainsString('sink_failures', self::summaryJson($r));
        self::assertSame([], array_filter(array_keys(self::keys($plain)), static fn (int|string $k): bool => str_starts_with((string) $k, 'obs')));
    }

    public function testDisabledEmitsNothing(): void
    {
        $h = new Harness(['enabled' => false] + self::LOG);
        $sink = new RecordingEventSink();

        $r = self::watcher($h, self::source(), self::dispatcher($sink))->run();

        self::assertSame(RunOutcome::Disabled, $r->outcome);
        self::assertSame([], $sink->received);
    }

    public function testSkippedNotDueEmitsNothing(): void
    {
        $h = new Harness(self::LOG);
        $h->clock->advance('+3 hours');
        $sink = new RecordingEventSink();

        $r = self::watcher($h, self::source(), self::dispatcher($sink))->run();

        self::assertSame(RunOutcome::SkippedNotDue, $r->outcome);
        self::assertSame([], $sink->received);
    }

    public function testSkippedDoneEmitsRunOnly(): void
    {
        $h = new Harness(self::LOG);
        self::watcher($h, self::source(), null)->run();
        $sink = new RecordingEventSink();
        $h->clock->advance('+10 minutes');

        $r = self::watcher($h, self::source(), self::dispatcher($sink))->run();

        self::assertSame(RunOutcome::SkippedDone, $r->outcome);
        self::assertSame(['run' => 1], self::byType(self::events($sink)));
        self::assertSame('skipped_done', self::events($sink)[0]['outcome']);
    }

    public function testDryRunPrintsEventsWritesNothing(): void
    {
        $h = new Harness(self::LOG);
        self::ready($h);
        $sink = new RecordingEventSink();
        $before = self::keys($h);
        $printed = '';
        $counters = new ObserveCounters($h->state, 'svc', 2592000);

        self::watcher($h, self::source(), self::dispatcher($sink), $counters, new RunOptions(dryRun: true, estimate: true), static function (string $s) use (&$printed): void {
            $printed .= $s;
        })->run();

        self::assertSame([], $sink->received);
        self::assertStringContainsString("== DRY RUN events ==\n{\"schema\":\"watcher.event.v2\"", $printed);
        self::assertSame($before, self::keys($h));
    }

    public function testSinkFailureDoesNotChangeOutcomeOrExitCode(): void
    {
        $h = new Harness(self::LOG);
        self::ready($h);
        $baseline = self::watcher($h, self::source(), null)->run();

        $h2 = new Harness(self::LOG);
        self::ready($h2);
        $counters = new ObserveCounters($h2->state, 'svc', 2592000);
        $broken = new RecordingEventSink(new \RuntimeException('disk full /secret/path'));

        $r = self::watcher($h2, self::source(), self::dispatcher($broken), $counters)->run();

        self::assertSame($baseline->outcome, $r->outcome);
        self::assertSame($baseline->exitCode(), $r->exitCode());
        self::assertSame(count($h->notifier->sent), count($h2->notifier->sent));
        self::assertSame(1.0, $counters->snapshot()['sink_failures']['log']);
        $json = (array) json_decode(self::summaryJson($r), true);
        self::assertSame(['log' => 1], $json['sink_failures']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $json['run_id']);
    }

    public function testSinkFailureAppearsInNextHeartbeat(): void
    {
        $h = new Harness(self::LOG);
        self::ready($h);
        $counters = new ObserveCounters($h->state, 'svc', 2592000);
        self::watcher($h, self::source(), self::dispatcher(new RecordingEventSink(new \RuntimeException('x'))), $counters)->run();
        $h->nextWindow();
        $sink = new RecordingEventSink();

        self::watcher($h, self::source(), self::dispatcher($sink), $counters)->run();

        $hb = array_values(array_filter(self::events($sink), static fn (array $e): bool => $e['event_type'] === 'heartbeat'))[0];
        self::assertSame(['sink_failed:log'], $hb['warnings']);

        $h->nextWindow();
        $clean = new RecordingEventSink();
        self::watcher($h, self::source(), self::dispatcher($clean), $counters)->run();
        $hb = array_values(array_filter(self::events($clean), static fn (array $e): bool => $e['event_type'] === 'heartbeat'))[0];
        self::assertSame([], $hb['warnings'], 'a clean flush clears the warning');
    }

    public function testDeliveryFailureEvent(): void
    {
        $h = new Harness(self::LOG);
        self::ready($h);
        $h->notifier->failWith(new DeliveryFailed('slack delivery failed: HTTP 429 https://hooks.invalid/SECRET', 429));
        $sink = new RecordingEventSink();

        $r = self::watcher($h, self::source(), self::dispatcher($sink))->run();

        self::assertSame(RunOutcome::Failed, $r->outcome);
        $df = array_values(array_filter(self::events($sink), static fn (array $e): bool => $e['event_type'] === 'delivery_failure'));
        self::assertNotSame([], $df);
        self::assertSame(['slack', 'digest', 'http_429', 429], [$df[0]['channel'], $df[0]['kind'], $df[0]['status_class'], $df[0]['http_status']]);
        self::assertStringNotContainsString('SECRET', (string) json_encode(self::events($sink)));
    }

    public function testConfigWarnings(): void
    {
        $h = new Harness(['observe' => ['http' => ['enabled' => true, 'url' => 'https://ingest.example.com/in', 'include_text' => true]]] + self::LOG);
        self::ready($h);
        $sink = new RecordingEventSink();

        self::watcher($h, self::source(), self::dispatcher($sink))->run();

        $codes = array_column(array_filter(self::events($sink), static fn (array $e): bool => $e['event_type'] === 'config_warning'), 'code');
        self::assertContains('include_text_external', $codes);
    }

    public function testHeartbeatFlagOffEmitsNoHeartbeatEvent(): void
    {
        $h = new Harness(['heartbeat' => ['enabled' => false]] + self::LOG);
        self::ready($h);
        $sink = new RecordingEventSink();

        self::watcher($h, self::source(), self::dispatcher($sink))->run();

        self::assertArrayNotHasKey('heartbeat', self::byType(self::events($sink)));
    }

    public function testDigestDisabledEmitsNoFindings(): void
    {
        $h = new Harness(['notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x', 'enabled' => false]]] + self::LOG);
        self::ready($h);
        $sink = new RecordingEventSink();

        self::watcher($h, self::source(), self::dispatcher($sink))->run();

        $types = self::byType(self::events($sink));
        self::assertArrayNotHasKey('finding', $types);
        self::assertSame(1, $types['run']);
    }
}
