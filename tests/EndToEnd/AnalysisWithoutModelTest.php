<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Watcher\Analyze\GeminiProvider;
use Watcher\Analyze\OpenAiProvider;
use Watcher\Analyze\ProviderAnalyzer;
use Watcher\Analyze\ProviderRegistry;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Watcher;

/**
 * Spec 019 (behaviours 1, 2, 3, 6, 7): a run whose provider cannot be used delivers its digest without AI, says so in one line,
 * exits 0, and sends nothing more to that provider in the run. Replayed replies only; no network.
 */
final class AnalysisWithoutModelTest extends TestCase
{
    private const SECRET = 'sk-test-key';

    /** @return array<string, array{string, string, string}> provider, fixture, the reason the digest must name */
    public static function skips(): array
    {
        return [
            'gemini 401 authentication' => ['gemini', 'unauthorised.json', 'authentication failed'],
            'openai 401 authentication' => ['openai', 'unauthorised.json', 'authentication failed'],
            'openai insufficient quota' => ['openai', 'insufficient_quota.json', 'quota exhausted'],
            'gemini payment required' => ['gemini', 'payment_required.json', 'quota exhausted'],
        ];
    }

    /**
     * @param list<Response> $responses
     *
     * @return array{0: Harness, 1: \Watcher\RunResult, 2: int}
     */
    private function runWith(string $provider, array $responses): array
    {
        $h = new Harness();
        $baseline = Harness::events(Level::Warning, 'Alpha failed in a unique way', 5, 'Alpha');
        $h->watcher(new FakeLogSource(Harness::totalsOf($baseline), $baseline))->run();
        $h->nextWindow();
        $events = Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha');
        $source = new FakeLogSource(Harness::totalsOf($events), $events);

        $requests = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(static function (callable $next) use ($requests): callable {
            return static function (RequestInterface $request, array $options) use ($next, $requests) {
                $requests->append($request);

                return $next($request, $options);
            };
        });
        $http = new Client(['handler' => $stack]);
        $adapter = match ($provider) {
            'openai' => new OpenAiProvider($http, self::SECRET, 'https://openai.example.test', 60, 0),
            'gemini' => new GeminiProvider($http, self::SECRET, 'https://gemini.example.test', 60, 0),
            default => throw new \LogicException("no adapter for $provider"),
        };
        $analyzer = new ProviderAnalyzer(ProviderRegistry::single($adapter, $provider));
        $config = WatchConfig::fromArray($h->config());
        $result = (new Watcher($config, $source, $h->state, $analyzer, $h->notifier, $h->clock))->run();

        return [$h, $result, count($requests)];
    }

    /** @return list<Response> the recorded failure, with the status its provider sends for it */
    private static function reply(string $provider, string $fixture): array
    {
        $status = match ($fixture) {
            'unauthorised.json' => 401,
            'insufficient_quota.json' => 429,
            'payment_required.json' => 402,
            default => throw new \LogicException("no status for $fixture"),
        };

        return [new Response($status, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../Fixtures/providers/' . $provider . '/' . $fixture))];
    }

    #[DataProvider('skips')]
    public function testTheDigestIsDeliveredWithoutAiAndTheRunExitsZero(string $provider, string $fixture, string $reason): void
    {
        [$h, $result, $requests] = $this->runWith($provider, self::reply($provider, $fixture));

        self::assertSame(RunOutcome::Reported, $result->outcome);
        self::assertSame(0, $result->exitCode());
        $texts = $h->sentTexts();
        $digest = implode("\n", $texts);
        self::assertStringContainsString('⚠️ AI analysis skipped: ' . $provider . ' ' . $reason . '; groups shown without AI summary', $digest);
        self::assertStringContainsString('⚪ *not analysed* (1)', $digest, 'the group is still shown, without an AI summary');
        self::assertSame(1, $requests, 'one request, then none to that provider in the run');
    }

    #[DataProvider('skips')]
    public function testNoSecretUrlOrBodyTextReachesTheDigest(string $provider, string $fixture, string $reason): void
    {
        [$h] = $this->runWith($provider, self::reply($provider, $fixture));

        $digest = implode("\n", $h->sentTexts());
        self::assertStringNotContainsString(self::SECRET, $digest);
        self::assertStringNotContainsString('https://', $digest);
        self::assertStringNotContainsString('Incorrect API key', $digest);
        self::assertStringNotContainsString('exceeded your current quota', $digest);
        self::assertStringNotContainsString('Your prepaid credit', $digest);
    }

    /** Two runs on one UTC day with the same provider and reason: one notice, not one per run. */
    public function testTheNoticeIsSentOncePerDay(): void
    {
        $h = new Harness();
        $baseline = Harness::events(Level::Warning, 'Alpha failed in a unique way', 5, 'Alpha');
        $h->watcher(new FakeLogSource(Harness::totalsOf($baseline), $baseline))->run();
        $h->nextWindow();
        $events = Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha');
        $unauthorised = (string) file_get_contents(__DIR__ . '/../Fixtures/providers/openai/unauthorised.json');
        $adapter = new OpenAiProvider(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(401, [], $unauthorised), new Response(401, [], $unauthorised)]))]), self::SECRET, 'https://openai.example.test', 60, 0);
        $watcher = fn (FakeLogSource $source): Watcher => new Watcher(WatchConfig::fromArray($h->config()), $source, $h->state, new ProviderAnalyzer(ProviderRegistry::single($adapter, 'openai')), $h->notifier, $h->clock);

        $watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();
        $h->clock->advance('+2 hours'); // the same UTC day
        $watcher(new FakeLogSource(Harness::totalsOf($events), $events))->run();

        $notices = array_filter($h->sentTexts(), static fn (string $t): bool => str_contains($t, 'AI analysis skipped for'));
        self::assertCount(1, $notices, 'one notice for the day');
    }

    /** The notice is sent again on the next UTC day, for the same reason. */
    public function testTheNoticeIsSentAgainOnTheNextDay(): void
    {
        [$h] = $this->runWith('openai', self::reply('openai', 'unauthorised.json'));
        $h->nextWindow();
        $events = Harness::events(Level::Error, 'Beta failed in another way', 5, 'Beta');
        $source = new FakeLogSource(Harness::totalsOf($events), $events);
        $adapter = new OpenAiProvider(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(401, [], '{}')]))]), self::SECRET, 'https://openai.example.test', 60, 0);
        (new Watcher(WatchConfig::fromArray($h->config()), $source, $h->state, new ProviderAnalyzer(ProviderRegistry::single($adapter, 'openai')), $h->notifier, $h->clock))->run();

        $notices = array_filter($h->sentTexts(), static fn (string $t): bool => str_contains($t, 'AI analysis skipped for'));
        self::assertCount(2, $notices, 'one notice per UTC day');
    }

    public function testATransientFailureStillFailsTheRun(): void
    {
        $unavailable = new Response(503, ['Content-Type' => 'application/json'], '{"error":{"message":"busy"}}');
        [$h, $result] = $this->runWith('openai', [$unavailable]);

        self::assertSame(RunOutcome::Failed, $result->outcome);
        $texts = $h->sentTexts();
        self::assertStringContainsString('analysis failed', $texts[count($texts) - 1]);
    }
}
