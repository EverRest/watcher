<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalysisRequest;
use Watcher\Analyze\AnthropicAnalyzer;
use Watcher\Analyze\TriageRequest;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;
use Watcher\RunOutcome;
use Watcher\Tests\EndToEnd\Harness;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\SpyLogger;
use Watcher\Watcher;

/** FR-040 (T103): service_context is bounded, scanned, and part of the cached prefix of every request. */
final class ServiceContextTest extends TestCase
{
    public function testConfigAcceptsExactlyOneThousandCharactersAndRejectsMore(): void
    {
        $h = new Harness(['service_context' => str_repeat('é', 1000)]);
        self::assertSame(1000, mb_strlen(WatchConfig::fromArray($h->config())->serviceContext));

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('service_context');
        WatchConfig::fromArray((new Harness(['service_context' => str_repeat('a', 1001)]))->config());
    }

    /** @return list<TriageRequest|AnalysisRequest> */
    private function runWith(string $context, ?SpyLogger $log = null): array
    {
        $h = new Harness(['service_context' => $context]);
        $events = Harness::events(Level::Error, 'Disk quota exceeded', 4);
        $make = fn (): Watcher => new Watcher(WatchConfig::fromArray($h->config()), new FakeLogSource(Harness::totalsOf($events), $events), $h->state, $h->analyzer, $h->notifier, $h->clock, $log);
        $make()->run();
        $h->nextWindow();
        self::assertSame(RunOutcome::Reported, $make()->run()->outcome);

        return $h->analyzer->allRequests();
    }

    public function testPipelineScansTheContextForEveryRequestKeepingNumbers(): void
    {
        $reqs = $this->runWith('Mail goes out as ops@example.org; about 40 req/s peak.');

        self::assertCount(2, $reqs); // one triage, one analysis
        foreach ($reqs as $req) {
            self::assertStringNotContainsString('ops@example.org', $req->serviceContext);
            self::assertStringContainsString('<EMAIL>', $req->serviceContext);
            self::assertStringContainsString('40 req/s', $req->serviceContext);
        }
    }

    public function testUnscannableContextBecomesEmptyAndIsLoggedWithoutItsText(): void
    {
        $log = new SpyLogger();
        $reqs = $this->runWith("bad \xFF bytes secret-marker", $log);

        foreach ($reqs as $req) {
            self::assertSame('', $req->serviceContext);
        }
        $lines = implode("\n", array_column($log->records, 1));
        self::assertStringContainsString('service_context', $lines);
        self::assertStringNotContainsString('secret-marker', $lines);
    }

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    public function testAnthropicPutsTheContextInTheCachedPrefixOfTriageAndAnalysis(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../Fixtures/anthropic/message-triage-valid.json')),
            new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../Fixtures/anthropic/message-valid.json')),
        ]));
        /** @var list<array{request: \Psr\Http\Message\RequestInterface}> $log */
        $log = [];
        $this->history = &$log; // Guzzle fills it by reference
        $stack->push(Middleware::history($log));
        $analyzer = new AnthropicAnalyzer('k', new Client(['handler' => $stack, 'http_errors' => false]));
        $t = new DateTimeImmutable('2026-10-23T05:00:00Z');
        $g = new RedactedGroup('fp-aaa', 'Timeout', Level::Error, null, [], 3, null, GroupStatusLabel::New, [], $t, $t);

        $analyzer->triage(new TriageRequest([$g], 'CONTEXT-MARK payments', 'm', 100));
        $analyzer->analyze(new AnalysisRequest([$g], 'CONTEXT-MARK payments', 'm', 100));

        self::assertCount(2, $this->history);
        foreach ($this->history as $h) {
            $body = json_decode((string) $h['request']->getBody(), true);
            $system = $body['system'];
            self::assertStringContainsString('CONTEXT-MARK payments', implode("\n", array_column($system, 'text')));
            self::assertSame('ephemeral', $system[array_key_last($system)]['cache_control']['type']);
            self::assertStringNotContainsString('CONTEXT-MARK', json_encode($body['messages'], JSON_THROW_ON_ERROR));
        }
    }
}
