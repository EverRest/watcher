<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnthropicSdkProvider;
use Watcher\Analyze\GeminiProvider;
use Watcher\Analyze\ModelProvider;
use Watcher\Analyze\OpenAiProvider;

/**
 * Principle I for the provider layer: the body an adapter puts on the wire carries what the analyzer handed it and no
 * seeded personal value (tests/Fixtures/privacy/seeded-values.php). The input here is already reduced, as the pipeline
 * delivers it: templates with placeholders, never raw context.
 */
final class ProviderOutgoingBodyPrivacyTest extends TestCase
{
    private const SYSTEM = 'You judge error groups. Forbidden: stating facts absent from the input.';
    private const USER = '{"groups":[{"f":"fp-aaa","t":"Payment gateway timeout for <EMAIL> on <ID>","n":41}]}';

    /** @return array<string, array{0: string}> */
    public static function providers(): array
    {
        return ['anthropic' => ['anthropic'], 'openai' => ['openai'], 'gemini' => ['gemini']];
    }

    /** @return list<string> */
    private static function seeded(): array
    {
        /** @var list<string> $values */
        $values = require __DIR__ . '/../../Fixtures/privacy/seeded-values.php';

        return $values;
    }

    #[DataProvider('providers')]
    public function testOutgoingBodyCarriesNoSeededValue(string $name): void
    {
        $body = $this->capturedBody($name);

        foreach (self::seeded() as $value) {
            self::assertStringNotContainsString($value, $body, "seeded value reached the $name request body");
        }
    }

    #[DataProvider('providers')]
    public function testOutgoingBodyCarriesTheInputAndNothingElse(string $name): void
    {
        $body = $this->capturedBody($name);

        // Quotes in the payload are JSON-escaped on the wire, so check a quote-free stretch of it.
        self::assertStringContainsString('Payment gateway timeout for <EMAIL> on <ID>', $body, "$name must send the user payload as given");
        self::assertStringContainsString('judge error groups', $body, "$name must send the system prompt as given");
    }

    private function capturedBody(string $name): string
    {
        /** @var \ArrayObject<int, RequestInterface> $history */
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $this->okReply($name))]));
        $stack->push(static function (callable $next) use ($history): callable {
            return static function (RequestInterface $request, array $options) use ($next, $history) {
                $history->append($request);

                return $next($request, $options);
            };
        });
        $provider = $this->provider($name, new Client(['handler' => $stack]));

        $provider->create('model-under-test', 1000, self::SYSTEM, self::USER, ['type' => 'object'], false);

        self::assertCount(1, $history, 'exactly one request');

        return (string) self::first($history)->getBody();
    }

    private function provider(string $name, Client $http): ModelProvider
    {
        return match ($name) {
            'anthropic' => new AnthropicSdkProvider($http, 'sk-test', 'https://provider.example.test', 60, 0),
            'openai' => new OpenAiProvider($http, 'sk-test', 'https://provider.example.test', 60, 0),
            'gemini' => new GeminiProvider($http, 'sk-test', 'https://provider.example.test', 60, 0),
            default => throw new \LogicException("no adapter for $name"),
        };
    }

    /** @param \ArrayObject<int, RequestInterface> $history */
    private static function first(\ArrayObject $history): RequestInterface
    {
        return $history[0] ?? throw new \LogicException('no request was sent');
    }

    private function okReply(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/providers/' . $name . '/ok.json');
    }
}
