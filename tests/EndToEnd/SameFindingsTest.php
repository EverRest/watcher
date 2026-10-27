<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\ProviderAnalyzer;
use Watcher\Analyze\GeminiProvider;
use Watcher\Analyze\OpenAiProvider;
use Watcher\Analyze\ProviderRegistry;
use Watcher\Config\WatchConfig;
use Watcher\Level;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Watcher;

/**
 * Findings are the same before and after 0.5.0 for each provider's recorded reply (plan 017, decision B). The digest text is
 * compared line by line, except the footer line that carried tokens and cost (it starts with "— tokens"), which is removed from
 * both sides. The baseline was recorded from the 0.4.1 code (tests/Fixtures/findings-baseline-017.json).
 */
final class SameFindingsTest extends TestCase
{
    private const BASELINE = __DIR__ . '/../Fixtures/findings-baseline-017.json';
    private const FOOTER = '— tokens';

    public function testFindingsMatchTheBaselineForEachProvider(): void
    {
        $baseline = json_decode((string) file_get_contents(self::BASELINE), true, 512, JSON_THROW_ON_ERROR);
        foreach (['openai', 'gemini'] as $provider) {
            self::assertSame(self::strip($baseline[$provider]), self::strip(self::digestFor($provider)), $provider);
        }
    }

    /** @param list<string> $texts @return list<string> */
    /**
     * @param list<string> $texts
     * @return list<string>
     */
    private static function strip(array $texts): array
    {
        return array_map(static fn (string $text): string => implode("\n", array_filter(explode("\n", $text), static fn (string $line): bool => !str_starts_with($line, self::FOOTER))), $texts);
    }

    /** @return list<string> the digest texts the run sent */
    private static function digestFor(string $provider): array
    {
        $harness = new Harness(['analysis' => ['triage' => ['enabled' => false]]]);
        $events = Harness::events(Level::Error, 'Alpha failed in a unique way', 5, 'Alpha');
        $source = new FakeLogSource(Harness::totalsOf($events), $events);
        (new Watcher(WatchConfig::fromArray($harness->config()), $source, $harness->state, $harness->analyzer, $harness->notifier, $harness->clock))->run();
        $harness->nextWindow();

        // The recorded reply names the fingerprint the analyzer really derives for this window (3ba2fceef742).
        $raw = str_replace('fp-aaa', '3ba2fceef742', (string) file_get_contents(__DIR__ . '/../Fixtures/providers/' . $provider . '/ok.json'));
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $raw)]));
        $http = new Client(['handler' => $stack]);
        $adapter = match ($provider) {
            'openai' => new OpenAiProvider($http, 'sk-test-key', 'https://openai.example.test', 60, 0),
            'gemini' => new GeminiProvider($http, 'sk-test-key', 'https://gemini.example.test', 60, 0),
            default => throw new \LogicException("no adapter for $provider"),
        };
        $analyzer = new ProviderAnalyzer(ProviderRegistry::single($adapter, $provider));
        (new Watcher(WatchConfig::fromArray($harness->config()), $source, $harness->state, $analyzer, $harness->notifier, $harness->clock))->run();

        return $harness->sentTexts();
    }
}
