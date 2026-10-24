<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Operate\JsonSummary;
use Watcher\RunOutcome;
use Watcher\RunResult;
use Watcher\Source\LogEvent;
use Watcher\State\Keys;
use Watcher\State\Purgeable;
use Watcher\State\StateStore;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\RecordingStateStore;
use Watcher\Tests\Fakes\RoutedLogSource;
use Watcher\Tests\Fakes\SpyLogger;
use Watcher\Watcher;

/** Spec 012: a switched-off feature does nothing; with `enabled` true and no other key nothing changed. */
final class FeatureFlagsTest extends TestCase
{
    /** @return list<LogEvent> */
    private static function alpha(int $n = 5): array
    {
        return Harness::events(Level::Error, 'Alpha failed in a unique way', $n, 'Alpha');
    }

    /** @return list<LogEvent> */
    private static function beta(int $n = 5): array
    {
        return Harness::events(Level::Error, 'Beta exploded unexpectedly', $n, 'Beta');
    }

    /** @param list<LogEvent> $events */
    private static function src(array $events): FakeLogSource
    {
        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    /**
     * Window 1 (baseline) then window 2 with a new group: a reported run, unless something is switched off.
     *
     * @param array<string, mixed> $patch
     * @return array{0: Harness, 1: RunResult, 2: FakeLogSource, 3: RecordingStateStore}
     */
    private static function twoWindows(array $patch = [], ?\Watcher\Source\LogSource $second = null): array
    {
        $h = new Harness($patch);
        $h->state = $rec = new RecordingStateStore($h->state);
        $h->watcher(self::src(self::alpha()))->run();
        $h->nextWindow();
        $rec->calls = [];
        $h->notifier->sent = [];
        $h->notifier->attempts = 0;
        $source = self::src([...self::alpha(), ...self::beta()]);
        $result = $h->watcher($second ?? $source)->run();

        return [$h, $result, $source, $rec];
    }

    private static function digest(Harness $h): string
    {
        return str_replace("\u{200B}", '', html_entity_decode(implode("\n", $h->sentTexts())));
    }

    public function testMasterSwitchOffByDefaultDoesNothingAtAll(): void
    {
        $h = new Harness();
        $config = $h->config();
        unset($config['enabled']);
        $h->state = $rec = new RecordingStateStore($h->state);
        $source = self::src(self::alpha());

        $r = (new Watcher(WatchConfig::fromArray($config), $source, $h->state, $h->analyzer, $h->notifier, $h->clock))->run();

        self::assertSame(RunOutcome::Disabled, $r->outcome);
        self::assertSame(0, $r->exitCode());
        self::assertSame('outcome=disabled', $r->summary());
        self::assertSame([], $source->calls);
        self::assertSame([], $rec->calls);
        self::assertSame(0, $h->notifier->attempts);
        self::assertSame([], $h->analyzer->allRequests());
        $json = json_decode((new JsonSummary())->render($r->summaryData ?? self::fail('no summary')), true);
        self::assertSame('disabled', $json['outcome']);
        self::assertSame('svc', $json['service']);
    }

    public function testMasterSwitchOffAlsoSkipsADryRun(): void
    {
        $h = new Harness(['enabled' => false]);
        $out = '';
        $r = $h->watcher(self::src(self::alpha()), new \Watcher\Operate\RunOptions(true, false, false), static function (string $s) use (&$out): void {
            $out .= $s;
        })->run();

        self::assertSame(RunOutcome::Disabled, $r->outcome);
        self::assertSame('', $out);
    }

    public function testWithNoNewKeyTheStateKeysAreTheLegacyOnes(): void
    {
        [$h, $r, , $rec] = self::twoWindows();

        self::assertSame(RunOutcome::Reported, $r->outcome);
        foreach ($rec->calls as [, $key]) {
            self::assertStringNotContainsString('@', $key);
            self::assertMatchesRegularExpression('/^(' . implode('|', [Keys::SNAP, Keys::LOCK, Keys::RUN, Keys::SPEND, Keys::ALERT, Keys::VERDICT, Keys::TRIAGE, Keys::REPORTED, Keys::HEARTBEAT, Keys::WEEK]) . '):/', $key);
        }
        self::assertStringContainsString('Beta', self::digest($h) . json_encode($h->analyzer->requests, JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    public function testHeartbeatOffWritesAndReadsNoHeartbeatKeys(): void
    {
        [, $r, , $rec] = self::twoWindows(['heartbeat' => ['enabled' => false, 'weekly_summary' => true]]);

        self::assertSame(RunOutcome::Reported, $r->outcome);
        foreach ($rec->calls as [, $key]) {
            self::assertDoesNotMatchRegularExpression('/^(hb|trig|hbweek|hbsent):/', $key);
        }
    }

    public function testPurgeOffNeverPurges(): void
    {
        $h = new Harness(['purge' => ['enabled' => false]]);
        $h->state = $store = new PurgeSpyStore($h->state);
        $h->watcher(self::src(self::alpha()))->run();

        self::assertSame(0, $store->purges);

        $on = new Harness();
        $on->state = $store = new PurgeSpyStore($on->state);
        $on->watcher(self::src(self::alpha()))->run();
        self::assertSame(1, $store->purges);
    }

    public function testDependenciesOffReadsNothingAndSendsNoContext(): void
    {
        $dep = new FakeLogSource(['error' => 6], Harness::events(Level::Error, 'Pool exhausted', 6));
        $dep2 = new FakeLogSource([], []);
        $own1 = new RoutedLogSource(['core-api' => $dep2], self::src(self::alpha()));
        $patch = ['dependencies' => ['enabled' => false, 'core-api' => ['stream_tags' => ['app' => 'core-api']]]];
        $h = new Harness($patch);
        $h->state = $rec = new RecordingStateStore($h->state);
        $h->watcher($own1)->run();
        $h->nextWindow();
        $own2 = new RoutedLogSource(['core-api' => $dep], self::src([...self::alpha(), ...Harness::events(Level::Error, 'GET http://core-api/api returned 500', 5)]));
        $r = $h->watcher($own2)->run();

        self::assertSame(RunOutcome::Reported, $r->outcome);
        self::assertSame([], $dep->calls);
        self::assertSame([], $dep2->calls);
        self::assertSame([], $h->analyzer->requests[0]->dependencyContext);
        foreach ($rec->calls as [, $key]) {
            self::assertStringNotContainsString(':dep:', $key);
        }
    }

    public function testDeployOffQueriesNoVersionsAndShowsNoRelease(): void
    {
        $source = new FakeLogSource(Harness::totalsOf([...self::alpha(), ...self::beta()]), [...self::alpha(), ...self::beta()]);
        [$h, , $src] = self::twoWindows(['deploy' => ['enabled' => false, 'release' => 'v9', 'commit' => 'abc', 'version_label' => 'version']], $source);

        self::assertSame([], $source->callsTo('versions'));
        self::assertStringNotContainsString('v9', self::digest($h));
        self::assertNotSame($src, $source);
    }

    public function testDeployOnQueriesVersions(): void
    {
        $source = new FakeLogSource(Harness::totalsOf([...self::alpha(), ...self::beta()]), [...self::alpha(), ...self::beta()]);
        [$h] = self::twoWindows(['deploy' => ['release' => 'v9', 'commit' => 'abc', 'version_label' => 'version']], $source);

        self::assertNotSame([], $source->callsTo('versions'));
        self::assertStringContainsString('v9', self::digest($h));
    }

    public function testLinksOffAddNoLogOrCodeLinks(): void
    {
        $links = ['grafana' => ['base_url' => 'https://grafana.test', 'datasource_uid' => 'loki-uid']];
        $repo = ['provider' => 'gitlab', 'web_url' => 'https://git.test/acme/svc', 'path_map' => []];
        [$on] = self::twoWindows(['links' => $links, 'repo' => $repo, 'deploy' => ['commit' => 'abc']]);
        self::assertStringContainsString('grafana.test', self::digest($on));

        [$off] = self::twoWindows(['links' => $links + ['logs' => ['enabled' => false], 'code' => ['enabled' => false]], 'repo' => $repo, 'deploy' => ['commit' => 'abc']]);
        self::assertStringNotContainsString('grafana.test', self::digest($off));
        self::assertStringNotContainsString('git.test', self::digest($off));
        self::assertStringNotContainsString('|log>', (string) json_encode($off->notifier->sent[0]['message']->sections));
        self::assertStringNotContainsString('|code', (string) json_encode($off->notifier->sent[0]['message']->sections));

        [$logsOnly] = self::twoWindows(['links' => $links + ['code' => ['enabled' => false]]]);
        self::assertStringContainsString('grafana.test', self::digest($logsOnly));
    }

    public function testServiceContextOffSendsNoContext(): void
    {
        [$h] = self::twoWindows(['service_context' => ['enabled' => false, 'text' => 'We take payments.']]);
        self::assertSame('', $h->analyzer->triageRequests[0]->serviceContext);

        [$on] = self::twoWindows(['service_context' => 'We take payments.']);
        self::assertSame('We take payments.', $on->analyzer->triageRequests[0]->serviceContext);
    }

    public function testWatchRulesOffMatchNothing(): void
    {
        [$on] = self::twoWindows(['watch_rules' => ['beta' => ['template' => '/Beta/']]]);
        $matches = array_merge(...array_map(static fn ($g): array => $g->watchMatches, $on->analyzer->triageRequests[0]->groups));
        self::assertSame(['beta'], $matches);

        [$off] = self::twoWindows(['watch_rules' => ['enabled' => false, 'beta' => ['template' => '/Beta/']]]);
        $matches = array_merge(...array_map(static fn ($g): array => $g->watchMatches, $off->analyzer->triageRequests[0]->groups));
        self::assertSame([], $matches);
    }

    public function testMuteOffMutesNothing(): void
    {
        [, $on] = self::twoWindows(['mute' => [['template' => '/Alpha|Beta/']]]);
        self::assertSame(RunOutcome::Quiet, $on->outcome);

        [, $off] = self::twoWindows(['mute' => ['enabled' => false, ['template' => '/Alpha|Beta/']]]);
        self::assertSame(RunOutcome::Reported, $off->outcome);
    }

    public function testLevelAnomalyOffReadsNoTotalsAndStoresNone(): void
    {
        [$h, , $source] = self::twoWindows(['level_anomaly' => ['enabled' => false]]);

        self::assertSame([], $source->callsTo('totals'));
        $snap = $h->state->get(Keys::snapshot('svc', Watcher::windowKey($h->windowEnd()))) ?? self::fail('no snapshot');
        self::assertArrayNotHasKey('totals', $snap);
        self::assertArrayHasKey('groups', $snap);
    }

    public function testEscalationOffNeverUsesTheEscalationModel(): void
    {
        $h = new Harness([]);
        $h->analyzer->confidence = 0.2;
        $h->watcher(self::src(self::alpha()))->run();
        $h->nextWindow();
        $h->watcher(self::src([...self::alpha(), ...self::beta()]))->run();
        self::assertContains('m-e', array_map(static fn ($r): string => $r->model, $h->analyzer->requests), 'escalation is on by default');

        $off = new Harness(['selection' => ['escalation' => ['enabled' => false]]]);
        $off->analyzer->confidence = 0.2;
        $off->watcher(self::src(self::alpha()))->run();
        $off->nextWindow();
        $r = $off->watcher(self::src([...self::alpha(), ...self::beta()]))->run();
        self::assertSame(RunOutcome::Reported, $r->outcome);
        self::assertNotContains('m-e', array_map(static fn ($r): string => $r->model, $off->analyzer->requests));
        self::assertNotEmpty($off->analyzer->requests);
    }

    public function testTriageOffAnalysesDirectlyWithNoTriageCall(): void
    {
        [$h, $r] = self::twoWindows(['analysis' => ['triage' => ['enabled' => false]]]);

        self::assertSame(RunOutcome::Reported, $r->outcome);
        self::assertSame([], $h->analyzer->triageRequests);
        self::assertCount(1, $h->analyzer->requests);
        self::assertStringContainsString('Problem', self::digest($h));
    }

    public function testBaselineOffUsesThePreviousWindowOnly(): void
    {
        $patch = ['baseline' => ['mode' => 'rolling', 'windows' => 3]];
        [$on] = self::twoWindows($patch);
        [$off] = self::twoWindows(['baseline' => ['mode' => 'rolling', 'windows' => 3, 'enabled' => false]]);

        self::assertNotSame(self::digest($on), self::digest($off), 'rolling falls back with a note, previous does not');
        self::assertStringNotContainsString('rolling', self::digest($off));
    }

    public function testGroupingOffUsesExactTokenCountClusters(): void
    {
        // Same token count, almost nothing in common: similarity grouping keeps them apart, the old exact clusters merge them.
        $events = [
            ...Harness::events(Level::Error, 'Cache miss for key alpha', 3),
            ...Harness::events(Level::Error, 'Order shipped for user beta', 3),
            ...Harness::events(Level::Error, 'Quota hit for tenant gamma', 3),
        ];
        $texts = [];
        foreach ([true, false] as $on) {
            $h = new Harness(['grouping' => ['enabled' => $on], 'redaction' => ['min_group_events_for_text' => 1]]);
            $h->watcher(self::src(self::alpha()))->run();
            $h->nextWindow();
            $h->watcher(self::src([...self::alpha(), ...$events]))->run();
            $texts[(int) $on] = json_encode($h->analyzer->triageRequests[0]->groups, JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        self::assertNotSame($texts[1], $texts[0]);
    }

    public function testBudgetAlertsOffPostsNoThresholdAlertAndTouchesNoAlertKey(): void
    {
        $patch = ['analysis' => ['daily_budget_usd' => 1.0]];
        foreach ([true, false] as $alertsOn) {
            $h = new Harness($patch + ['budget_alerts' => ['enabled' => $alertsOn]]);
            $h->state = $rec = new RecordingStateStore($h->state);
            $h->watcher(self::src(self::alpha()))->run();
            $h->nextWindow();
            $h->analyzer->inputTokens = 100000;
            $rec->calls = [];
            $h->notifier->sent = [];
            $h->watcher(self::src([...self::alpha(), ...self::beta()]))->run();
            $alerts = array_filter($h->notifier->sent, static fn (array $s): bool => str_contains($s['message']->text, 'budget'));
            $alertKeys = array_filter($rec->calls, static fn (array $c): bool => str_starts_with($c[1], Keys::ALERT . ':'));
            if ($alertsOn) {
                self::assertNotEmpty($alerts);
            } else {
                self::assertSame([], $alerts);
                self::assertSame([], $alertKeys);
            }
        }
    }

    public function testNoticeOffStillFailsLoudlyInTheHostLogAndExitCode(): void
    {
        $h = new Harness(['notify' => ['notice' => ['enabled' => false]]]);
        $h->hostLog = $log = new SpyLogger();
        $h->analyzer->analyzeFailure = new AnalyzerFailed('boom');
        $h->watcher(self::src(self::alpha()))->run();
        $h->nextWindow();
        $h->notifier->attempts = 0;
        $r = $h->watcher(self::src([...self::alpha(), ...self::beta()]))->run();

        self::assertSame(RunOutcome::Failed, $r->outcome);
        self::assertSame(1, $r->exitCode());
        self::assertSame(0, $h->notifier->attempts);
        self::assertNotEmpty($log->records);
    }

    public function testNoticeOffAlsoSilencesTheBaselineAndWeeklyLines(): void
    {
        $h = new Harness(['notify' => ['notice' => ['enabled' => false]], 'heartbeat' => ['weekly_summary' => true]]);
        $h->state = $rec = new RecordingStateStore($h->state);
        $r = $h->watcher(self::src(self::alpha()))->run();

        self::assertSame(RunOutcome::Baselined, $r->outcome);
        self::assertSame(0, $h->notifier->attempts);
        foreach ($rec->calls as [, $key]) {
            self::assertStringStartsNotWith('hbsent:', $key);
        }
    }

    public function testDigestOffEndsQuietBeforeAnyModelCall(): void
    {
        [$h, $r] = self::twoWindows(['notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.invalid/x', 'enabled' => false]]]);

        self::assertSame(RunOutcome::Quiet, $r->outcome);
        self::assertSame([], $h->analyzer->allRequests());
        self::assertSame(0, $h->notifier->attempts);
        self::assertSame('digest_disabled', $r->summaryData?->reason);
    }

    public function testEnvironmentPrefixesEveryStateKeyAndShowsInHeaderAndSummary(): void
    {
        [$h, $r, , $rec] = self::twoWindows(['environment' => 'int']);

        self::assertSame(RunOutcome::Reported, $r->outcome);
        foreach ($rec->calls as [, $key]) {
            self::assertStringContainsString('svc%40int', $key, 'every key carries service@environment');
        }
        self::assertStringContainsString('int', self::digest($h));
        $json = json_decode((new JsonSummary())->render($r->summaryData ?? self::fail('no summary')), true);
        self::assertSame('int', $json['environment']);
        self::assertSame('svc', $json['service']);
    }

    public function testSummaryJsonHasNoEnvironmentKeyWhenUnset(): void
    {
        [, $r] = self::twoWindows();
        $json = json_decode((new JsonSummary())->render($r->summaryData ?? self::fail('no summary')), true);

        self::assertArrayNotHasKey('environment', $json);
    }
}

/** A store that counts purge() calls. */
final class PurgeSpyStore implements StateStore, Purgeable
{
    public int $purges = 0;

    public function __construct(private readonly StateStore $inner)
    {
    }

    public function purge(int $maxFiles = 5000, float $maxSeconds = 2.0): int
    {
        ++$this->purges;

        return 0;
    }

    public function get(string $key): ?array
    {
        return $this->inner->get($key);
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
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
}
