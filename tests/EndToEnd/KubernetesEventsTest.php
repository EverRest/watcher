<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Operate\RunOptions;
use Watcher\Reduce\Normalizer;
use Watcher\RunOutcome;
use Watcher\Source\Kubernetes\KubeApi;
use Watcher\Source\KubernetesEventsSource;
use Watcher\Source\LogEvent;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FakeLogSource;

/** Spec 008 end to end: recorded fixtures through a Guzzle MockHandler, fakes everywhere else, no network. */
final class KubernetesEventsTest extends TestCase
{
    private const DIR = __DIR__ . '/../Fixtures/kubernetes/';

    /** @var list<array<string, mixed>> */
    private array $history = [];

    private static function fixture(string $name, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) file_get_contents(self::DIR . $name));
    }

    /** @return list<Response> */
    private static function fullRead(): array
    {
        return [self::fixture('events-core.json'), self::fixture('pods.json'), self::fixture('pods-page2.json')];
    }

    /** @return array<string, mixed> */
    private static function patch(): array
    {
        return [
            'kubernetes' => ['namespaces' => ['shop'], 'api' => ['server' => 'https://k8s.example', 'token' => 'BEARER-TEST-TOKEN-0123456789']],
            'events' => ['driver' => 'kubernetes'],
        ];
    }

    /** @param list<Response|\Throwable|callable> $queue */
    private function events(Harness $h, array $queue): KubernetesEventsSource
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $historyLog = [];
        $this->history = &$historyLog; // Guzzle fills it by reference
        $stack->push(Middleware::history($historyLog));
        $c = WatchConfig::fromArray($h->config());
        self::assertNotNull($c->kubernetes);
        $api = new KubeApi(new Client(['handler' => $stack]), $c->kubernetes, $h->clock, static function (int $s): void {
        });

        return new KubernetesEventsSource($api, $c->kubernetes, $h->clock, new Normalizer(), $c->stateKey, 86400, $c->state['retention']);
    }

    /** @return list<LogEvent> */
    private static function alpha(): array
    {
        return Harness::events(Level::Error, 'Alpha service exploded badly', 5, 'AlphaException');
    }

    private function dayOne(Harness $h): void
    {
        $h->clock->set(new DateTimeImmutable('2026-10-23T06:00:00Z'));
    }

    private function dayTwo(Harness $h): void
    {
        $h->clock->set(new DateTimeImmutable('2026-10-24T06:00:00Z'));
    }

    /** @return array<string, array<mixed>> every live state value */
    private static function stored(Harness $h): array
    {
        return $h->state instanceof \Watcher\Tests\Fakes\InMemoryStateStore ? $h->state->all() : [];
    }

    /** @return list<\Watcher\Privacy\RedactedGroup> */
    private static function sentGroups(Harness $h): array
    {
        $out = [];
        foreach ($h->analyzer->allRequests() as $r) {
            foreach ($r->groups as $g) {
                $out[$g->fingerprint] = $g;
            }
        }

        return array_values($out);
    }

    public function testPodEventsArriveBesideLogGroupsAndCollapse(): void
    {
        $h = new Harness(self::patch());
        $events = $this->events($h, [...self::fullRead(), ...self::fullRead()]);
        // Day one is a Warning baseline: an Error first run now reports (FR-035), and this test is about pod events.
        $warning = Harness::events(Level::Warning, 'Alpha service exploded badly', 5, 'AlphaException');
        $watcher = $h->watcher(new FakeLogSource(Harness::totalsOf($warning), $warning), null, null, $events);

        $this->dayOne($h);
        self::assertSame(RunOutcome::Baselined, $watcher->run()->outcome);
        $this->dayTwo($h);
        $watcher = $h->watcher(new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha()), null, null, $events);
        self::assertSame(RunOutcome::Reported, $watcher->run()->outcome);

        $groups = self::sentGroups($h);
        $pod = array_values(array_filter($groups, static fn ($g): bool => $g->origin === 'k8s-events'));
        $byReason = [];
        foreach ($pod as $g) {
            $byReason[$g->errorType][] = $g;
        }
        self::assertCount(1, $byReason['BackOff'], 'eight crash-looping pods are one group');
        self::assertSame(8, $byReason['BackOff'][0]->pods);
        self::assertSame(array_sum(range(10, 17)), $byReason['BackOff'][0]->count);
        self::assertSame('Deployment', $byReason['BackOff'][0]->workloadKind);
        self::assertSame(2, $byReason['OOMKilled'][0]->pods);
        self::assertArrayHasKey('Failed', $byReason);
        self::assertArrayHasKey('Evicted', $byReason);
        self::assertNotEmpty(array_filter($groups, static fn ($g): bool => $g->origin === null && $g->errorType === 'AlphaException'), 'the log group is still there');

        $text = implode("\n", $h->sentTexts());
        self::assertStringContainsString('pod event: BackOff · 8 pods · Deployment', $text);
    }

    /** One BackOff event for the pod, observed 30 minutes before the clock, as an events.k8s.io/v1 list. */
    private static function freshEvent(Harness $h, int $count): Response
    {
        $list = json_decode((string) file_get_contents(self::DIR . 'events-v1.json'), true, flags: JSON_THROW_ON_ERROR);
        $item = $list['items'][0];
        $item['series'] = ['count' => $count, 'lastObservedTime' => $h->clock->now()->modify('-30 minutes')->format('Y-m-d\\TH:i:s\\Z')];
        $list['items'] = [$item];

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($list, JSON_THROW_ON_ERROR));
    }

    public function testADeadLogSourceIsReportedEvenWhilePodEventsKeepArriving(): void
    {
        $h = new Harness(self::patch());
        $queue = [];
        for ($i = 0; $i < 4; ++$i) {
            $queue = [...$queue, self::freshEvent($h, 5), self::fixture('pods.json'), self::fixture('pods-page2.json')];
        }
        $watcher = $h->watcher(new FakeLogSource([], []), null, null, $this->events($h, $queue));
        $this->dayOne($h);

        $texts = [];
        for ($i = 1; $i <= 3; ++$i) {
            $queue = [];
            $watcher->run();
            $texts[$i] = implode("\n", $h->sentTexts());
            $h->nextWindow();
        }

        self::assertStringNotContainsString('consecutive windows', $texts[2]);
        self::assertStringContainsString('0 events read for 3 consecutive windows', $texts[3], 'pod events are not log lines: a dead log source stays visible');
    }

    /** The log source of a run that takes longer than the ledger's one-minute reuse: its read finds the clock two minutes on. */
    private static function slowLogs(Harness $h): \Watcher\Source\LogSource
    {
        $inner = new FakeLogSource([], []);

        return new class ($inner, $h->clock) implements \Watcher\Source\LogSource {
            public function __construct(private readonly \Watcher\Source\LogSource $inner, private readonly \Watcher\Tests\Fakes\FrozenClock $clock)
            {
            }

            public function totals(\Watcher\Schedule\Window $window, \Watcher\Source\StreamSelector $selector): array
            {
                return $this->inner->totals($window, $selector);
            }

            public function lines(\Watcher\Schedule\Window $window, \Watcher\Source\StreamSelector $selector, \Watcher\Source\ReadFilter $filter): iterable
            {
                $this->clock->advance('+2 minutes');

                return $this->inner->lines($window, $selector, $filter);
            }

            public function lastReadTruncated(): bool
            {
                return false;
            }

            public function versions(\Watcher\Schedule\Window $window, \Watcher\Source\StreamSelector $selector, string $label): array
            {
                return [];
            }
        };
    }

    /** @return array<string, mixed> */
    private static function beat(Harness $h): array
    {
        return $h->state->get(Keys::heartbeat('svc')) ?? [];
    }

    public function testAStaleReplayDuringTheRunIsInTheHeartbeatEvenWithNoticesOff(): void
    {
        $h = new Harness(self::patch() + ['notify' => ['notice' => ['enabled' => false]]]);
        $queue = [...self::fullRead(), ...self::fullRead(), ...self::fullRead(), ...array_fill(0, 12, new Response(403, [], '{}'))]; // day one: trigger + re-read; day two: trigger
        $watcher = $h->watcher(self::slowLogs($h), null, null, $this->events($h, $queue));
        $this->dayOne($h);
        $watcher->run(); // fills the ledger
        $this->dayTwo($h);

        $result = $watcher->run(); // the trigger read works, the re-read inside the run (two minutes later) is refused

        $beat = self::beat($h);
        self::assertSame('stale', $beat['events'] ?? null);
        self::assertSame('k8s_ingest_failed:unauthorised', $beat['reason'] ?? null);
        self::assertSame('k8s_ingest_failed:unauthorised', $result->summaryData?->reason);
    }

    public function testATransientTriggerFailureThatTheRunReadRepairedLeavesNoFalseCode(): void
    {
        $h = new Harness(self::patch());
        $queue = [new Response(403, [], '{}'), ...self::fullRead(), ...self::fullRead()];
        $watcher = $h->watcher(new FakeLogSource([], []), null, null, $this->events($h, $queue));
        $this->dayOne($h);

        $result = $watcher->run();

        $beat = self::beat($h);
        self::assertSame('ok', $beat['events'] ?? null);
        self::assertNull($beat['reason'] ?? null, 'the digest is complete: the trigger blip is not the final state');
        self::assertNull($result->summaryData?->reason);
    }

    public function testAnUnreadClusterWithAnEmptyLedgerIsFlaggedAtRunTimeToo(): void
    {
        $h = new Harness(self::patch() + ['notify' => ['notice' => ['enabled' => false]]]);
        $watcher = $h->watcher(self::slowLogs($h), null, null, $this->events($h, array_fill(0, 30, new Response(403, [], '{}'))));
        $this->dayOne($h);
        $watcher->run();

        $beat = self::beat($h);
        self::assertSame('unavailable', $beat['events'] ?? null);
        self::assertSame('k8s_ingest_failed:unauthorised', $beat['reason'] ?? null);
    }

    public function testAStateStoreThatRefusesTheLedgerMakesTheRunDegradedNeverAllQuiet(): void
    {
        $h = new Harness(self::patch() + ['notify' => ['notice' => ['enabled' => false]]]);
        $refusing = new class ($h->state) implements \Watcher\State\StateStore {
            public function __construct(private readonly \Watcher\State\StateStore $inner)
            {
            }

            public function get(string $key): ?array
            {
                return $this->inner->get($key);
            }

            public function put(string $key, array $value, int $ttlSeconds): void
            {
                if (str_starts_with($key, 'evt')) {
                    throw new \RuntimeException('state value exceeds the readable size or structure budget'); // what the Redis codec throws
                }
                $this->inner->put($key, $value, $ttlSeconds);
            }

            public function acquire(string $key, string $owner, int $ttlSeconds): bool
            {
                return $this->inner->acquire($key, $owner, $ttlSeconds);
            }

            public function release(string $key, string $owner): void
            {
                $this->inner->release($key, $owner);
            }

            public function increment(string $key, float $by, int $ttlSeconds): float
            {
                return $this->inner->increment($key, $by, $ttlSeconds);
            }
        };
        $c = WatchConfig::fromArray($h->config());
        self::assertNotNull($c->kubernetes);
        $stack = HandlerStack::create(new MockHandler(array_merge(...array_fill(0, 6, self::fullRead()))));
        $api = new KubeApi(new Client(['handler' => $stack]), $c->kubernetes, $h->clock, static function (int $s): void {
        });
        $events = new KubernetesEventsSource($api, $c->kubernetes, $h->clock, new Normalizer(), $c->stateKey, 86400, $c->state['retention']);
        $this->dayOne($h);
        $watcher = new \Watcher\Watcher($c, new FakeLogSource([], []), $refusing, $h->analyzer, $h->notifier, $h->clock, events: $events);

        $result = $watcher->run();

        self::assertSame('unavailable', ($h->state->get(Keys::heartbeat('svc')) ?? [])['events'] ?? null, 'the unreadable ledger is a degraded state, not a quiet day');
        self::assertSame('k8s_ingest_failed:unavailable', $result->summaryData?->reason);
    }

    public function testTheTriggerIngestStopsAtTheRunDeadline(): void
    {
        $h = new Harness(self::patch() + ['schedule' => ['run_deadline' => 30]]);
        $logs = new FakeLogSource([], []);
        $slow = function () use ($h): Response {
            $h->clock->set($h->clock->now()->modify('+60 seconds')); // past the 30 s run deadline, inside the client's own two-minute budget
            return self::fixture('events-core.json');
        };
        $events = $this->events($h, [$slow, self::fixture('pods.json'), self::fixture('pods-page2.json')]);
        $watcher = $h->watcher($logs, null, null, $events);
        $h->clock->set(new DateTimeImmutable('2026-10-23T12:00:00Z')); // not the run_at hour: the trigger only feeds the ledger

        $watcher->run();

        self::assertCount(1, $this->history, 'the pods list was never requested: the guard covers the ingest');
    }

    public function testNothingSeededReachesAnyPayloadMessageOrState(): void
    {
        $h = new Harness(self::patch());
        $logs = new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha());
        $events = $this->events($h, [...self::fullRead(), ...self::fullRead()]);
        $watcher = $h->watcher($logs, null, null, $events);
        $this->dayOne($h);
        $watcher->run();
        $this->dayTwo($h);
        $watcher->run();

        $seeded = require self::DIR . 'seeded-values.php';
        $payload = json_encode(array_map(static fn ($g): array => $g->toArray(), self::sentGroups($h)), JSON_THROW_ON_ERROR);
        $messages = implode("\n", $h->sentTexts());
        $state = json_encode(self::stored($h), JSON_THROW_ON_ERROR);
        $services = json_encode(array_map(static fn ($r): string => serialize($r), $h->analyzer->allRequests()), JSON_THROW_ON_ERROR);

        self::assertNotEmpty($h->analyzer->allRequests());
        foreach ([...$seeded['values'], ...$seeded['podSuffixes'], ...$seeded['tokenAndCredentials']] as $value) {
            foreach (['model payload' => $payload, 'serialized requests' => $services, 'messages' => $messages, 'state' => $state] as $where => $haystack) {
                self::assertStringNotContainsString($value, $haystack, "$value leaked into $where");
            }
        }
        self::assertArrayHasKey(Keys::events('svc'), self::stored($h));
    }

    public function testANormalOnlyWindowIsQuietAndCostsNoTokens(): void
    {
        $h = new Harness(self::patch());
        $normal = json_encode(['items' => [
            ['metadata' => ['namespace' => 'shop'], 'involvedObject' => ['kind' => 'Pod', 'name' => 'api-7d9f8c6b5-x2k4q'], 'reason' => 'Pulled', 'type' => 'Normal', 'message' => 'ok', 'count' => 1, 'lastTimestamp' => '2026-10-24T05:00:00Z'],
        ]], JSON_THROW_ON_ERROR);
        $empty = json_encode(['items' => []], JSON_THROW_ON_ERROR);
        $read = [new Response(200, [], $normal), new Response(200, [], $empty)];
        $watcher = $h->watcher(new FakeLogSource([], []), null, null, $this->events($h, [...$read, ...$read]));

        $this->dayOne($h);
        $watcher->run();
        $this->dayTwo($h);
        $result = $watcher->run();

        self::assertSame(RunOutcome::Quiet, $result->outcome);
        self::assertSame([], $h->analyzer->allRequests());
    }

    public function testAnEventsFailureLeavesTheLogPartAndAddsOneNotice(): void
    {
        foreach ([401 => 'unauthorised', 403 => 'unauthorised', 429 => 'rate limited', 500 => 'unavailable'] as $status => $class) {
            $h = new Harness(self::patch());
            $logs = new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha());
            $bad = array_fill(0, 12, new Response($status, [], '{}'));
            $watcher = $h->watcher($logs, null, null, $this->events($h, $bad));
            $this->dayOne($h);
            $watcher->run();
            $this->dayTwo($h);
            $result = $watcher->run();

            self::assertSame(RunOutcome::Reported, $result->outcome, (string) $status);
            self::assertStringContainsString("Pod events were not read: $class", implode("\n", $h->sentTexts()), (string) $status);
            self::assertNotEmpty(array_filter(self::sentGroups($h), static fn ($g): bool => $g->errorType === 'AlphaException'));
        }
    }

    public function testAnUnexpectedErrorInTheClientDoesNotFailTheLogPart(): void
    {
        $h = new Harness(self::patch());
        $logs = new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha());
        $watcher = $h->watcher($logs, null, null, $this->events($h, array_fill(0, 12, new \RuntimeException('SSL CA bundle not found'))));
        $this->dayOne($h);
        $watcher->run();
        $this->dayTwo($h);
        $result = $watcher->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertStringContainsString('Pod events were not read: unavailable', implode("\n", $h->sentTexts()));
    }

    public function testAFailedDueHourReadStillReportsWhatTheHourlyReadsCollected(): void
    {
        $h = new Harness(self::patch());
        $logs = new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha());
        $watcher = $h->watcher($logs, null, null, $this->events($h, [...self::fullRead(), ...array_fill(0, 12, new Response(429, [], ''))]));
        $this->dayOne($h);
        $watcher->run(); // reads the cluster: the ledger now holds the fixture
        $this->dayTwo($h);
        $result = $watcher->run(); // the cluster answers 429 at the due hour

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $text = implode("\n", $h->sentTexts());
        self::assertStringContainsString('pod event: BackOff', $text);
        self::assertStringContainsString('latest read failed (rate limited)', $text);
        self::assertStringNotContainsString('Pod events were not read', $text);
        self::assertSame('k8s_ingest_failed:rate_limited', $result->summaryData?->reason, 'fail closed: the summary names the unread cluster even when the digest went out');
    }

    public function testAnUnreadClusterIsNamedInTheSummaryEvenWithNoticesOff(): void
    {
        $h = new Harness(self::patch() + ['notify' => ['notice' => ['enabled' => false]]]);
        $watcher = $h->watcher(new FakeLogSource([], []), null, null, $this->events($h, array_fill(0, 12, new Response(403, [], '{}'))));
        $this->dayOne($h);
        $watcher->run();
        $this->dayTwo($h);

        self::assertSame('k8s_ingest_failed:unauthorised', $watcher->run()->summaryData?->reason);
    }

    public function testThePlainNoticeNamesTheServiceAndSurvivesADeliveryFailure(): void
    {
        $h = new Harness(self::patch());
        $watcher = $h->watcher(new FakeLogSource([], []), null, null, $this->events($h, array_fill(0, 12, new Response(403, [], '{}'))));
        $this->dayOne($h);
        $watcher->run();
        $this->dayTwo($h);
        $result = $watcher->run();
        self::assertSame(RunOutcome::Quiet, $result->outcome);
        self::assertStringContainsString('svc', implode("\n", $h->sentTexts()));

        $h2 = new Harness(self::patch());
        $w2 = $h2->watcher(new FakeLogSource([], []), null, null, $this->events($h2, array_fill(0, 12, new Response(403, [], '{}'))));
        $this->dayOne($h2);
        $w2->run();
        $h2->notifier->failWith(new \Watcher\Notify\DeliveryFailed('boom'));
        $this->dayTwo($h2);
        self::assertSame(RunOutcome::Quiet, $w2->run()->outcome, 'a failed notice never crashes the run');
    }

    public function testAnEventsFailureOnTheBaselineRunIsAnnounced(): void
    {
        $h = new Harness(self::patch());
        $watcher = $h->watcher(new FakeLogSource([], []), null, null, $this->events($h, array_fill(0, 12, new Response(403, [], '{}'))));
        $this->dayOne($h);
        $result = $watcher->run();

        self::assertSame(RunOutcome::Baselined, $result->outcome);
        self::assertStringContainsString('Pod events were not read: unauthorised', implode("\n", $h->sentTexts()));
    }

    public function testPodEventGroupsCarryNoLogLinks(): void
    {
        $h = new Harness(self::patch() + ['links' => ['grafana' => ['base_url' => 'https://grafana.example', 'datasource_uid' => 'loki-1']]]);
        $logs = new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha());
        $watcher = $h->watcher($logs, null, null, $this->events($h, [...self::fullRead(), ...self::fullRead()]));
        $this->dayOne($h);
        $watcher->run();
        $this->dayTwo($h);
        $watcher->run();

        $blocks = preg_split('/(?=^• )/m', implode("\n", $h->sentTexts())) ?: [];
        $pod = array_filter($blocks, static fn (string $b): bool => str_contains($b, 'pod event:'));
        self::assertNotEmpty($pod);
        foreach ($pod as $block) {
            self::assertStringNotContainsString('grafana.example', $block, 'a log query cannot find a pod event');
            self::assertStringNotContainsString('Links:', $block);
        }
    }

    public function testAnEventsFailureOnAQuietDayIsNeverSilent(): void
    {
        $h = new Harness(self::patch());
        $watcher = $h->watcher(new FakeLogSource([], []), null, null, $this->events($h, array_fill(0, 12, new Response(403, [], '{}'))));
        $this->dayOne($h);
        $watcher->run();
        $this->dayTwo($h);
        $result = $watcher->run();

        self::assertSame(RunOutcome::Quiet, $result->outcome);
        self::assertStringContainsString('Pod events were not read: unauthorised', implode("\n", $h->sentTexts()));
    }

    public function testTwentyFourHourlyTriggersFeedTheDailyRunWithoutAGapNote(): void
    {
        $h = new Harness(self::patch());
        $logs = new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha());
        $queue = [];
        for ($i = 0; $i < 26; ++$i) {
            $queue = [...$queue, ...self::fullRead()];
        }
        $watcher = $h->watcher($logs, null, null, $this->events($h, $queue));

        $this->dayOne($h);
        $watcher->run(); // baseline at 06:00
        for ($hour = 1; $hour < 24; ++$hour) {
            $h->clock->set((new DateTimeImmutable('2026-10-23T06:00:00Z'))->modify("+$hour hours"));
            self::assertSame(RunOutcome::SkippedNotDue, $watcher->run()->outcome);
        }
        $this->dayTwo($h);
        $result = $watcher->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        $back = array_values(array_filter(self::sentGroups($h), static fn ($g): bool => $g->errorType === 'BackOff'));
        self::assertSame(array_sum(range(10, 17)), $back[0]->count, 'every occurrence once, however many reads');
        self::assertStringNotContainsString('possible gap', implode("\n", $h->sentTexts()));
        self::assertCount(25, array_filter($this->history, static fn (array $x): bool => str_contains($x['request']->getUri()->getPath(), '/events')));
    }

    public function testAMissedTriggerIsStatedNeverSilent(): void
    {
        $h = new Harness(self::patch());
        $logs = new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha());
        $watcher = $h->watcher($logs, null, null, $this->events($h, [...self::fullRead(), ...self::fullRead()]));
        $this->dayOne($h);
        $watcher->run();
        $this->dayTwo($h); // no trigger in between: more than the cluster's one hour of retention
        $watcher->run();

        self::assertStringContainsString('possible gap', implode("\n", $h->sentTexts()));
    }

    public function testADryRunReadsTheClusterAndWritesNoLedger(): void
    {
        $h = new Harness(self::patch());
        $logs = new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha());
        $this->dayTwo($h);
        $watcher = $h->watcher($logs, new RunOptions(dryRun: true, estimate: true), static function (string $s): void {
        }, $this->events($h, self::fullRead()));
        $watcher->run();

        self::assertNotEmpty($this->history);
        self::assertArrayNotHasKey(Keys::events('svc'), self::stored($h));
    }

    public function testTheJsonSummaryAndHostLogCarryOnlyNumbersAndFixedWords(): void
    {
        $h = new Harness(self::patch());
        $h->hostLog = $log = new \Watcher\Tests\Fakes\SpyLogger();
        $logs = new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha());
        $watcher = $h->watcher($logs, null, null, $this->events($h, [...self::fullRead(), ...array_fill(0, 12, new Response(403, [], 'BEARER-TEST-TOKEN-0123456789'))]));
        $this->dayOne($h);
        $watcher->run();
        $this->dayTwo($h);
        $ok = $watcher->run();

        self::assertNotNull($ok->summaryData);
        $json = (new \Watcher\Operate\JsonSummary())->render($ok->summaryData);
        self::assertStringContainsString('"events":{"status":"stale"', $json);

        $seeded = require self::DIR . 'seeded-values.php';
        $all = $json . json_encode($log->records, JSON_THROW_ON_ERROR);
        foreach ([...$seeded['values'], ...$seeded['podSuffixes'], ...$seeded['tokenAndCredentials']] as $value) {
            self::assertStringNotContainsString($value, $all, $value);
        }
    }

    public function testAFailedIngestWithoutALoggerIsNotSilentTheHeartbeatCarriesTheReasonCode(): void
    {
        $h = new Harness(self::patch()); // the harness has no host logger
        $logs = new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha());
        $watcher = $h->watcher($logs, null, null, $this->events($h, array_fill(0, 30, new Response(403))));
        $this->dayOne($h);
        $watcher->run();

        $beat = $h->state->get(Keys::heartbeat('svc')) ?? [];
        self::assertSame('k8s_ingest_failed:unauthorised', $beat['reason'] ?? null);
        $status = new \Watcher\Operate\HeartbeatStatus('ok', $beat, 0, 1, new DateTimeImmutable('2026-10-23T06:00:00Z'), null, null);
        self::assertStringContainsString('reason=k8s_ingest_failed:unauthorised', $status->text('svc'));
    }

    public function testTheHeartbeatRecordsTheEventsStatusOnlyWhenConfigured(): void
    {
        $h = new Harness(self::patch());
        $logs = new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha());
        $watcher = $h->watcher($logs, null, null, $this->events($h, [...self::fullRead(), ...self::fullRead()]));
        $this->dayOne($h);
        $watcher->run();
        $this->dayTwo($h);
        $watcher->run();
        self::assertSame('ok', $h->state->get(Keys::heartbeat('svc'))['events'] ?? null);

        $plain = new Harness();
        $this->dayOne($plain);
        $plain->watcher(new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha()))->run();
        self::assertArrayNotHasKey('events', $plain->state->get(Keys::heartbeat('svc')) ?? []);
    }

    public function testWithoutEventsNothingIsAskedOfAnyClusterAndOutputIsIdentical(): void
    {
        $plain = new Harness();
        $this->dayOne($plain);
        $plain->watcher(new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha()))->run();
        $plain->nextWindow();
        $plain->watcher(new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha()))->run();

        // The section exists and a source is even handed in, but `events` is not declared: it must stay invisible.
        $h = new Harness(['kubernetes' => self::patch()['kubernetes']]);
        $source = $this->events(new Harness(self::patch()), [...self::fullRead(), ...self::fullRead()]);
        $this->dayOne($h);
        $watcher = $h->watcher(new FakeLogSource(Harness::totalsOf(self::alpha()), self::alpha()), null, null, $source);
        $watcher->run();
        $h->nextWindow();
        $watcher->run();

        self::assertSame([], $this->history);
        self::assertSame($plain->sentTexts(), $h->sentTexts());
        self::assertEquals(array_map(static fn ($g): array => $g->toArray(), self::sentGroups($plain)), array_map(static fn ($g): array => $g->toArray(), self::sentGroups($h)));
        self::assertSame(array_keys(self::stored($plain)), array_keys(self::stored($h)));
        self::assertSame([], array_filter(array_keys(self::stored($h)), static fn (int|string $k): bool => str_starts_with((string) $k, 'evt:')));
    }
}
