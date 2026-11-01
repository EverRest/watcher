<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Watcher\Analyze\ProviderAnalyzer;
use Watcher\Analyze\GeminiProvider;
use Watcher\Analyze\OpenAiProvider;
use Watcher\Analyze\ProviderRegistry;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\RunOutcome;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Watcher;

/**
 * The real adapters through a whole run, each over recorded replies: a failing provider produces one visible failure notice
 * (FR-015, SC-006), and the budget decision is the same whichever provider would have answered (T029).
 */
final class ProviderRunTest extends TestCase
{
    /** @return array<string, array{string, string}> provider, the recorded failure to replay */
    public static function failures(): array
    {
        return [
            'gemini 401' => ['gemini', 'unauthorised.json'],
            'openai 500' => ['openai', 'server_error.json'],
            'gemini 429' => ['gemini', 'rate_limited.json'],
        ];
    }

    /**
     * One window read, then a second run whose provider is replayed with $responses.
     *
     * @param list<Response|ConnectException> $responses
     * @param array<string, mixed>            $configPatch
     *
     * @return array{0: Harness, 1: \Watcher\RunResult, 2: int}
     */
    private function runWith(string $provider, array $responses, array $configPatch = []): array
    {
        $h = new Harness($configPatch);
        $baseline = Harness::events(Level::Warning, 'Alpha failed in a unique way', 5, 'Alpha'); // below Error: the first run only records (FR-035)
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
            'openai' => new OpenAiProvider($http, 'sk-test-key', 'https://openai.example.test', 60, 0),
            'gemini' => new GeminiProvider($http, 'sk-test-key', 'https://gemini.example.test', 60, 0),
            default => throw new \LogicException("no adapter for $provider"),
        };
        $analyzer = new ProviderAnalyzer(ProviderRegistry::single($adapter, $provider));
        $config = WatchConfig::fromArray($h->config());
        $result = (new Watcher($config, $source, $h->state, $analyzer, $h->notifier, $h->clock))->run();

        return [$h, $result, count($requests)];
    }

    /** @return list<Response> */
    private static function fixture(string $provider, string $name): array
    {
        return [new Response(
            (int) (str_contains($name, 'unauthorised') ? 401 : (str_contains($name, 'rate') ? 429 : 500)),
            ['Content-Type' => 'application/json'],
            (string) file_get_contents(__DIR__ . '/../Fixtures/providers/' . $provider . '/' . $name),
        )];
    }

    #[DataProvider('failures')]
    public function testAFailingProviderGivesOneVisibleFailureNotice(string $provider, string $fixture): void
    {
        [$h, $result] = $this->runWith($provider, self::fixture($provider, $fixture));

        self::assertSame(RunOutcome::Failed, $result->outcome);
        $texts = $h->sentTexts();
        self::assertNotEmpty($texts);
        self::assertStringContainsString('analysis failed', $texts[count($texts) - 1]);
        foreach ($texts as $text) {
            self::assertStringNotContainsString('nothing found', $text, 'a failed run must never look like an empty one');
        }
    }

    public function testAnUnreachableProviderFailsTheRunLoudly(): void
    {
        $unreachable = new ConnectException('connection refused', new Request('POST', 'https://gemini.example.test'));
        [$h, $result] = $this->runWith('gemini', [$unreachable]);

        self::assertSame(RunOutcome::Failed, $result->outcome);
        $texts = $h->sentTexts();
        self::assertNotEmpty($texts);
        self::assertStringContainsString('analysis failed', $texts[count($texts) - 1]);
    }

}
