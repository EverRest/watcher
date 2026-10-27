<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnthropicSdkProvider;
use Watcher\Analyze\CostCalculator;
use Watcher\Analyze\GeminiProvider;
use Watcher\Analyze\OpenAiProvider;
use Watcher\Analyze\Usage;

/** SC-004: the cost of one recorded reply equals its reported usage priced with the configured rates, for each provider. */
final class ProviderCostTest extends TestCase
{
    /** USD per million: input 2, output 10, cache read 0.5, cache write 2.5 (placeholders for the test only). */
    private const PRICES = [2.0, 10.0, 0.5, 2.5];

    public function testEachProviderCostsItsReportedUsage(): void
    {
        $cases = [
            'anthropic' => [new AnthropicSdkProvider(new Client(['handler' => HandlerStack::create($this->mock('anthropic'))]), 'k', 'https://a.example.test', 60, 0), 6100],
            'openai' => [new OpenAiProvider(new Client(['handler' => HandlerStack::create($this->mock('openai'))]), 'k', 'https://o.example.test', 60, 0), 3300],
            'gemini' => [new GeminiProvider(new Client(['handler' => HandlerStack::create($this->mock('gemini'))]), 'k', 'https://g.example.test', 60, 0), 3300],
        ];
        foreach ($cases as $name => [$provider, $microUsd]) {
            $reply = $provider->create('model-x', 900, 'system text', 'user text', ['type' => 'object'], true);
            $cost = (new CostCalculator(['model-x' => self::PRICES]))->cost(new Usage('model-x', $reply->inputTokens, $reply->outputTokens, $reply->cacheReadTokens, $reply->cacheWriteTokens));

            self::assertEqualsWithDelta($microUsd / 1_000_000, $cost, 1e-12, "$name cost");
        }
    }

    private function mock(string $name): MockHandler
    {
        return new MockHandler([new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../Fixtures/providers/' . $name . '/ok.json'))]);
    }
}
