<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnalysisResult;
use Watcher\Analyze\Analyzer;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\GeminiProvider;
use Watcher\Analyze\NoKeyProvider;
use Watcher\Analyze\ProviderAnalyzer;
use Watcher\Analyze\ProviderRegistry;
use Watcher\Analyze\TriageRequest;
use Watcher\Analyze\TriageResult;
use Watcher\Analyze\VerdictCache;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Notify\DeliveryFailed;
use Watcher\Notify\Destination;
use Watcher\Notify\Message;
use Watcher\Notify\Notifier;
use Watcher\RunOutcome;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\StreamSelector;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Watcher;
use Watcher\Schedule\Window;

/** Spec 019, review follow-ups: the edges of a run whose model cannot be used. No network. */
final class AnalysisWithoutModelEdgesTest extends TestCase
{
    /** A baseline window, then the clock at the next one. */
    private static function baselined(Harness $h): void
    {
        $baseline = Harness::events(Level::Warning, 'Alpha failed in a unique way', 5, 'Alpha');
        $h->watcher(new FakeLogSource(Harness::totalsOf($baseline), $baseline))->run();
        $h->nextWindow();
    }

    private static function triageThenQuota(): TriageThenQuota
    {
        return new TriageThenQuota();
    }

    public function testABrokenNoticeChannelDoesNotHoldBackTheDigest(): void
    {
        $h = new Harness();
        self::baselined($h);
        $events = Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha');
        $inner = $h->notifier;
        $notifier = new class ($inner) implements Notifier {
            public function __construct(private readonly Notifier $inner)
            {
            }

            public function send(Message $message, Destination $to): void
            {
                if (str_contains($message->text, 'AI analysis skipped for')) {
                    throw new DeliveryFailed('notice delivery failed: HTTP 500');
                }
                $this->inner->send($message, $to);
            }
        };
        $analyzer = new ProviderAnalyzer(ProviderRegistry::single(new NoKeyProvider(), 'openai'));

        $result = (new Watcher(WatchConfig::fromArray($h->config()), new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $analyzer, $notifier, $h->clock))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome, 'the digest went out; the notice failure is only logged');
        self::assertStringContainsString('⚠️ AI analysis skipped: openai no key', implode("\n", $h->sentTexts()));
    }

    public function testNoNoticeWhenNoticesAreOff(): void
    {
        $h = new Harness(['notify' => ['notice' => ['enabled' => false]]]);
        self::baselined($h);
        $events = Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha');
        $analyzer = new ProviderAnalyzer(ProviderRegistry::single(new NoKeyProvider(), 'openai'));

        $result = (new Watcher(WatchConfig::fromArray($h->config()), new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $analyzer, $h->notifier, $h->clock))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertCount(0, array_filter($h->sentTexts(), static fn (string $t): bool => str_contains($t, 'AI analysis skipped for')));
    }

    public function testNoVerdictIsKeptWhenAnalysisIsRefusedAfterTriage(): void
    {
        $h = new Harness();
        self::baselined($h);
        $events = Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha');
        $analyzer = self::triageThenQuota();
        $config = WatchConfig::fromArray($h->config());

        $result = (new Watcher($config, new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $analyzer, $h->notifier, $h->clock))->run();

        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertNotEmpty($analyzer->seen, 'triage ran');
        $cache = new VerdictCache($h->state, $config->stateKey, $config->state['retention']);
        foreach ($analyzer->seen as $g) {
            self::assertNull($cache->get($g->fingerprint), 'the triage verdict of a skipped run is not kept');
            self::assertSame(true, $h->state->get(Keys::reported($config->stateKey, $g->fingerprint))['withoutAi'] ?? null, 'marked reported without AI');
        }
    }

    public function testKnownIssueReferencesSurviveASkippedRun(): void
    {
        $h = new Harness(['known_issues' => ['entries' => [['fingerprint' => '3ba2fceef742', 'ticket' => 'T-1']]]]);
        self::baselined($h);
        $events = [...Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha'), ...Harness::events(Level::Error, 'Beta broke differently', 4, 'Beta')];
        $analyzer = new ProviderAnalyzer(ProviderRegistry::single(new NoKeyProvider(), 'openai'));
        $config = WatchConfig::fromArray($h->config());

        (new Watcher($config, new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $analyzer, $h->notifier, $h->clock))->run();

        self::assertInstanceOf(InMemoryStateStore::class, $h->state);
        $known = array_filter(array_keys($h->state->all()), static fn (string $k): bool => str_starts_with($k, Keys::known($config->stateKey, '')));
        self::assertNotEmpty($known, 'the known-issue reference written in the run is kept');
    }

    public function testGroupsTheDigestDoesNotListAreNotMarkedReported(): void
    {
        $h = new Harness(['selection' => ['min_level' => 'error']]);
        self::baselined($h);
        $events = [...Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha'), ...Harness::events(Level::Warning, 'Gamma is slow today', 40, 'Gamma')];
        $analyzer = self::triageThenQuota();
        $config = WatchConfig::fromArray($h->config());

        (new Watcher($config, new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $analyzer, $h->notifier, $h->clock))->run();

        $below = array_filter($analyzer->seen, static fn ($g): bool => !$g->level->atLeast(Level::Error));
        self::assertNotEmpty($below, 'a group below min_level was a candidate');
        foreach ($below as $g) {
            self::assertNull($h->state->get(Keys::reported($config->stateKey, $g->fingerprint)), 'not listed, so not marked reported');
        }
    }

    public function testASecondInvalidOutputNamesTheProvider(): void
    {
        $h = new Harness();
        self::baselined($h);
        $events = Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha');
        $bad = (string) file_get_contents(__DIR__ . '/../Fixtures/providers/gemini/text_not_json.json');
        $replies = array_map(static fn (): Response => new Response(200, ['Content-Type' => 'application/json'], $bad), range(1, 4));
        $adapter = new GeminiProvider(new Client(['handler' => HandlerStack::create(new MockHandler($replies))]), 'sk-test-key', 'https://gemini.example.test', 60, 0);
        $watcher = fn (): Watcher => new Watcher(WatchConfig::fromArray($h->config()), new FakeLogSource(Harness::totalsOf($events), $events), $h->state, new ProviderAnalyzer(ProviderRegistry::single($adapter, 'gemini')), $h->notifier, $h->clock);

        self::assertSame(RunOutcome::Failed, $watcher()->run()->outcome);
        self::assertSame(RunOutcome::Reported, $watcher()->run()->outcome);

        self::assertStringContainsString('⚠️ AI analysis skipped: gemini provider unavailable', implode("\n", $h->sentTexts()));
    }

    public function testCatchUpWindowsSendNoFurtherRequestAfterASkip(): void
    {
        $h = new Harness(['schedule' => ['every' => 'PT1H', 'timezone' => 'UTC', 'run_at' => null, 'catch_up' => 3, 'lock_ttl' => 4000, 'run_deadline' => 3900]]);
        $h->clock->set(new DateTimeImmutable('2026-10-08T09:30:00+00:00'));
        $seed = Harness::events(Level::Error, 'seed group', 2);
        $h->watcher(new FakeLogSource(Harness::totalsOf($seed), $seed))->run();
        $h->clock->advance('+5 hours');
        // A new group in every window, so each one would need the model.
        $source = new class () implements LogSource {
            private function inner(Window $window): FakeLogSource
            {
                $events = Harness::events(Level::Error, 'Window ' . $window->start->format('H') . ' failed', 3, 'W' . $window->start->format('H'));

                return new FakeLogSource(Harness::totalsOf($events), $events);
            }

            public function totals(Window $window, StreamSelector $selector): array
            {
                return $this->inner($window)->totals($window, $selector);
            }

            public function lines(Window $window, StreamSelector $selector, ReadFilter $filter): iterable
            {
                return $this->inner($window)->lines($window, $selector, $filter);
            }

            public function lastReadTruncated(): bool
            {
                return false;
            }

            public function versions(Window $window, StreamSelector $selector, string $label): array
            {
                return [];
            }
        };
        $mock = new MockHandler([new Response(401, [], '{}'), new Response(401, [], '{}'), new Response(401, [], '{}')]);
        $adapter = new GeminiProvider(new Client(['handler' => HandlerStack::create($mock)]), 'sk-test-key', 'https://gemini.example.test', 60, 0);

        (new Watcher(WatchConfig::fromArray($h->config()), $source, $h->state, new ProviderAnalyzer(ProviderRegistry::single($adapter, 'gemini')), $h->notifier, $h->clock))->run();

        self::assertSame(2, $mock->count(), 'one request in the run, then none for the later windows');
        self::assertGreaterThanOrEqual(2, substr_count(implode("\n", $h->sentTexts()), '⚠️ AI analysis skipped: gemini authentication failed'), 'each window still has its digest');
    }
}

/** Triage says interesting to everything it sees; analysis is refused for billing. Records what it was sent. */
final class TriageThenQuota implements Analyzer
{
    /** @var list<\Watcher\Privacy\RedactedGroup> */
    public array $seen = [];

    public function triage(TriageRequest $req): TriageResult
    {
        $verdicts = [];
        foreach ($req->groups as $g) {
            $this->seen[] = $g;
            $verdicts[$g->fingerprint] = 'interesting';
        }

        return new TriageResult($verdicts);
    }

    public function analyze(AnalysisRequest $req): AnalysisResult
    {
        throw new AnalyzerFailed('provider unavailable (quota exhausted)', false, 'quota exhausted', 'openai');
    }
}
