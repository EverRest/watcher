<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use DateTimeImmutable;
use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Operate\DryRunStateStore;
use Watcher\Reduce\Normalizer;
use Watcher\Schedule\Window;
use Watcher\Source\Kubernetes\KubeApi;
use Watcher\Source\Kubernetes\KubeSettings;
use Watcher\Source\KubernetesEventsSource;
use Watcher\Source\LogEvent;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

/**
 * The LogSource behaviours of the shared suite, adapted: an events source has no per-line version label (spec 008:
 * versions() is []), and its message is "Reason: masked text", so the shared line()-based cases cannot run unchanged.
 */
final class KubernetesEventsSourceTest extends TestCase
{
    private const DIR = __DIR__ . '/../Fixtures/kubernetes/';
    private const TOKEN = 'BEARER-TEST-TOKEN-0123456789';

    private FrozenClock $clock;
    private InMemoryStateStore $state;
    /** @var list<array<string, mixed>> */
    private array $history = [];

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-24T06:05:00Z'));
        $this->state = new InMemoryStateStore($this->clock);
        $this->history = [];

    }

    /** The ledger has been filled before: an event object created since the previous read is credited in full. */
    private function prime(): void
    {
        $ledger = new \Watcher\Source\Kubernetes\EventLedger($this->state, 'svc', 86400, 120, 3600, 30 * 86400);
        $ledger->load();
        $ledger->merge(new \Watcher\Source\Kubernetes\MappedRead([], [], 0), new DateTimeImmutable('2026-10-23T05:00:00Z'));
        $ledger->save();
    }

    private static function window(): Window
    {
        return new Window(new DateTimeImmutable('2026-10-23T06:00:00Z'), new DateTimeImmutable('2026-10-24T06:00:00Z'));
    }

    private static function selector(): StreamSelector
    {
        return new StreamSelector([]);
    }

    private static function fixture(string $name): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents(self::DIR . $name));
    }

    /** @return list<Response> one full read: events, then both pod pages */
    private static function fullRead(): array
    {
        return [self::fixture('events-core.json'), self::fixture('pods.json'), self::fixture('pods-page2.json')];
    }

    /**
     * @param list<Response|\Throwable> $queue
     * @param array<string, mixed> $settings
     */
    private function source(array $queue, array $settings = [], bool $bind = true): KubernetesEventsSource
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(\GuzzleHttp\Middleware::history($this->history));
        $s = KubeSettings::fromArray($settings + ['namespaces' => ['shop'], 'api' => ['server' => 'https://k8s.example', 'token' => self::TOKEN]], true, true, false);
        $api = new KubeApi(new Client(['handler' => $stack]), $s, $this->clock, static function (int $x): void {
        });
        $source = new KubernetesEventsSource($api, $s, $this->clock, new Normalizer(), 'svc', 86400, 30 * 86400);
        if ($bind) {
            $source->bindState($this->state);
        }

        return $source;
    }

    /**
     * @param list<string> $watch
     * @return list<LogEvent>
     */
    private function read(LogSource $s, Level $min = Level::Warning, array $watch = [], int $cap = 1000): array
    {
        return array_values([...$s->lines(self::window(), self::selector(), new ReadFilter($min, $watch, $cap))]);
    }

    public function testLinesYieldsLazilyOneLogEventPerLedgerEntryWithOccurrences(): void
    {
        $this->prime();
        $source = $this->source(self::fullRead());
        $lines = $source->lines(self::window(), self::selector(), new ReadFilter(Level::Warning, [], 1000));
        self::assertInstanceOf(Generator::class, $lines);
        $events = iterator_to_array($lines, false);

        self::assertContainsOnlyInstancesOf(LogEvent::class, $events);
        $backoff = array_values(array_filter($events, static fn (LogEvent $e): bool => $e->errorType === 'BackOff' && $e->workloadKind === 'Deployment'));
        self::assertCount(8, $backoff);
        self::assertSame(Level::Error, $backoff[0]->level);
        self::assertStringStartsWith('BackOff: ', $backoff[0]->message);
        self::assertSame('k8s-events', $backoff[0]->origin);
        self::assertSame(array_sum(range(10, 17)), array_sum(array_map(static fn (LogEvent $e): int => $e->occurrences, $backoff)));
        self::assertCount(8, array_unique(array_map(static fn (LogEvent $e): ?string => $e->podId, $backoff)));
        self::assertSame([], $backoff[0]->fields);
        self::assertSame([], $backoff[0]->frames);
    }

    public function testMinimumLevelAndWatchPatternsAreHonoured(): void
    {
        $warn = $this->read($this->source(self::fullRead()));
        self::assertSame([], array_filter($warn, static fn (LogEvent $e): bool => $e->level === Level::Info), 'Normal events are dropped by the minimum level');

        $this->history = [];
        $kept = $this->read($this->source(self::fullRead()), Level::Error, ['Pulled']);
        $info = array_filter($kept, static fn (LogEvent $e): bool => $e->level === Level::Info);
        self::assertNotEmpty($info);
        foreach ($info as $e) {
            self::assertSame('Pulled', $e->errorType);
        }

        $all = $this->read($this->source(self::fullRead()), Level::Debug);
        self::assertNotEmpty(array_filter($all, static fn (LogEvent $e): bool => $e->errorType === 'Started'));
    }

    public function testStopsAtTheEventCapAndReportsTruncation(): void
    {
        $source = $this->source(self::fullRead());
        self::assertFalse($source->lastReadTruncated());
        self::assertCount(3, $this->read($source, Level::Warning, [], 3));
        self::assertTrue($source->lastReadTruncated());

        $source = $this->source(self::fullRead());
        $n = count($this->read($source, Level::Warning, [], 1000));
        self::assertFalse($source->lastReadTruncated());

        $source = $this->source(self::fullRead());
        self::assertCount($n, $this->read($source, Level::Warning, [], $n));
        self::assertFalse($source->lastReadTruncated(), 'exactly the cap is not a truncation');
    }

    public function testTotalsCountDeduplicatedOccurrencesPerLevel(): void
    {
        $this->prime();
        $totals = $this->source(self::fullRead())->totals(self::window(), self::selector());

        // errors: 8 crash-looping pods (10..17), pull failure 3, evicted 1, failed mount 5, probe 40 (>= 3 repeats) and 2 (restarted pod), 2 OOMKilled signals
        self::assertSame(array_sum(range(10, 17)) + 3 + 1 + 5 + 40 + 2 + 2, $totals['error']);
        self::assertGreaterThan(0, $totals['warning']);
        self::assertGreaterThan(0, $totals['info']);
    }

    public function testVersionsIsAlwaysEmpty(): void
    {
        self::assertSame([], $this->source([])->versions(self::window(), self::selector(), 'version'));
    }

    public function testASecondReadInTheSameMinuteDoesNotAskTheClusterAgain(): void
    {
        $source = $this->source(self::fullRead());
        $this->read($source);
        $before = count($this->history);
        $this->read($source);
        $source->totals(self::window(), self::selector());

        self::assertSame($before, count($this->history));
    }

    public function testIngestPersistsAndAnUnchangedClusterAddsNothing(): void
    {
        $this->prime();
        $a = $this->source(self::fullRead());
        $first = array_sum(array_map(static fn (LogEvent $e): int => $e->occurrences, $this->read($a)));
        self::assertNotNull($this->state->get(Keys::events('svc')));

        $this->clock->advance('+1 hour');
        $b = $this->source(self::fullRead());
        $second = array_sum(array_map(static fn (LogEvent $e): int => $e->occurrences, $this->read($b)));

        self::assertSame($first, $second);
    }

    public function testDryRunSeesTheDataButWritesNothing(): void
    {
        $source = $this->source(self::fullRead(), [], false);
        $source->bindState(new DryRunStateStore($this->state));

        self::assertNotEmpty($this->read($source));
        self::assertNull($this->state->get(Keys::events('svc')));
    }

    public function testReadingNeedsABoundState(): void
    {
        $this->expectException(LogicException::class);
        $this->read($this->source(self::fullRead(), [], false));
    }

    /** @return array<string, array{int}> */
    public static function failures(): array
    {
        return ['unauthorized' => [401], 'forbidden' => [403], 'server error' => [500], 'unavailable' => [503], 'network' => [0]];
    }

    #[DataProvider('failures')]
    public function testFailuresBecomeSourceUnavailableWithoutLeakingTheToken(int $status): void
    {
        foreach ([
            fn (LogSource $s) => $s->totals(self::window(), self::selector()),
            fn (LogSource $s) => $this->read($s),
        ] as $call) {
            $queue = $status === 0
                ? array_fill(0, 3, new ConnectException('boom ' . self::TOKEN, new Request('GET', 'https://k8s.example')))
                : array_fill(0, 4, new Response($status, [], self::TOKEN));
            try {
                $call($this->source($queue));
                self::fail('SourceUnavailable expected');
            } catch (SourceUnavailable $e) {
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
                self::assertStringStartsWith('kubernetes ', $e->getMessage());
            }
        }
    }

    public function testAFailedFreshReadStillReplaysTheHourlyLedger(): void
    {
        $this->prime();
        $a = $this->source(self::fullRead());
        $good = array_sum(array_map(static fn (LogEvent $e): int => $e->occurrences, $this->read($a)));

        $this->clock->advance('+1 hour');
        $b = $this->source(array_fill(0, 8, new Response(429, [], '')));
        $events = $this->read($b);

        self::assertSame($good, array_sum(array_map(static fn (LogEvent $e): int => $e->occurrences, $events)));
        self::assertSame('rate limited', $b->ingestFailure());
    }

    public function testAProbeTemplateGetsOneLevelAcrossPodsInAWindow(): void
    {
        $mk = static fn (string $pod, int $count): array => ['metadata' => ['namespace' => 'shop', 'name' => "e-$pod"], 'involvedObject' => ['kind' => 'Pod', 'name' => $pod],
            'reason' => 'Unhealthy', 'type' => 'Warning', 'message' => 'Readiness probe failed', 'count' => $count, 'lastTimestamp' => '2026-10-23T12:00:00Z'];
        $list = new Response(200, [], json_encode(['items' => [$mk('api-7d9f8c6b5-aaaaa', 3), $mk('api-7d9f8c6b5-bbbbb', 2), $mk('api-7d9f8c6b5-ccccc', 2)]], JSON_THROW_ON_ERROR));
        $events = $this->read($this->source([$list, new Response(200, [], '{"items":[]}')]), Level::Debug);

        self::assertCount(3, $events);
        self::assertCount(1, array_unique(array_map(static fn (LogEvent $e): int => $e->level->value, $events)));
    }

    public function testAnOverlappingIngestLosesNothingAndWritesNothing(): void
    {
        $this->prime();
        $a = $this->source(self::fullRead());
        $this->read($a);
        $before = $this->state->get(Keys::events('svc'));

        $this->clock->advance('+1 hour');
        $this->state->acquire(Keys::lock('svc', 'events'), 'another-trigger', 60); // a concurrent ingest holds the ledger
        $b = $this->source(self::fullRead());
        $b->bindState($this->state);
        $b->ingest();

        self::assertSame($before, $this->state->get(Keys::events('svc')), 'the loser leaves the record as the winner wrote it');
        $this->state->release(Keys::lock('svc', 'events'), 'another-trigger');
        $b->ingest();
        self::assertNotNull($before);
        self::assertNotSame($before['last_ok'], $this->state->get(Keys::events('svc'))['last_ok'] ?? null);
    }

    public function testATriggerThatLosesTheLedgerLockSaysSoAndReloadsTheRecord(): void
    {
        $this->prime();
        $a = $this->source(self::fullRead());
        $this->read($a); // the winner: its read is in the stored record
        $this->clock->advance('+1 hour');
        $this->state->acquire(Keys::lock('svc', 'events'), 'another-trigger', 60);
        $b = $this->source(self::fullRead());
        $b->bindState($this->state);

        $b->ingest();

        self::assertSame('ledger busy', $b->ingestFailure(), 'a read that merged nothing is not presented as complete');
        self::assertSame(1, $b->readDiagnostics()['k8s_read_failed']);
        $this->state->release(Keys::lock('svc', 'events'), 'another-trigger');
        $b->ingest();
        self::assertNull($b->ingestFailure(), 'the next read that gets the lock clears it');
    }

    public function testTheMainSourceReportsItsCoverageAsReadDiagnostics(): void
    {
        $source = $this->source(self::fullRead());
        self::assertInstanceOf(\Watcher\Source\ReadDiagnostics::class, $source);
        self::assertSame(0, $source->readDiagnostics()['k8s_gaps'], 'nothing is read yet');
        $this->read($source);

        $d = $source->readDiagnostics();
        $c = $source->coverage(self::window());
        self::assertSame(count($c->gaps), $d['k8s_gaps']);
        self::assertSame($c->unattributed, $d['k8s_unattributed']);
        self::assertSame($c->capped ? 1 : 0, $d['k8s_record_full']);
        self::assertSame(0, $d['k8s_read_failed']);
    }

    public function testCoverageComesFromTheLedger(): void
    {
        $source = $this->source(self::fullRead());
        $this->read($source);

        $c = $source->coverage(self::window());
        self::assertTrue($c->shortHistory, 'the ledger was created after the window started');
    }
}
