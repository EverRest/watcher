<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\ModelReply;
use Watcher\Analyze\OpenAiProvider;

/** The OpenAI adapter: request shape, cache read tokens, stop reasons. Recorded replies only. */
final class OpenAiProviderTest extends TestCase
{
    private const SCHEMA = ['type' => 'object', 'properties' => ['findings' => ['type' => 'array']]];

    /** @return array{0: array<string, mixed>, 1: ModelReply} */
    private function call(string $fixture, bool $thinking = false): array
    {
        /** @var \ArrayObject<int, RequestInterface> $history */
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../Fixtures/providers/openai/' . $fixture))]));
        $stack->push(static function (callable $next) use ($history): callable {
            return static function (RequestInterface $request, array $options) use ($next, $history) {
                $history->append($request);

                return $next($request, $options);
            };
        });
        $reply = (new OpenAiProvider(new Client(['handler' => $stack]), 'sk-openai-test', 'https://openai.example.test', 60, 0))
            ->create('gpt-model', 900, 'system text', 'user text', self::SCHEMA, $thinking);

        self::assertCount(1, $history);
        $request = $history[0] ?? throw new \LogicException('no request was sent');
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return [$body, $reply];
    }

    public function testRequestCarriesTheSchemaAsResponseFormatAndTheSystemFirst(): void
    {
        [$body] = $this->call('ok.json');

        self::assertSame('gpt-model', $body['model']);
        self::assertSame(900, $body['max_completion_tokens']);
        self::assertSame([['role' => 'system', 'content' => 'system text'], ['role' => 'user', 'content' => 'user text']], $body['messages']);
        self::assertSame('json_schema', $body['response_format']['type']);
        self::assertSame(self::SCHEMA, $body['response_format']['json_schema']['schema']);
        self::assertFalse($body['response_format']['json_schema']['strict'], 'stripped schema is not strict-compatible');
    }

    public function testTriageAsksForNoReasoning(): void
    {
        [$off] = $this->call('ok.json');
        [$on] = $this->call('ok.json', true);

        self::assertSame('none', $off['reasoning_effort']);
        self::assertArrayNotHasKey('reasoning_effort', $on);
    }

    public function testCachedTokensAreReadTokensAndWritesAreZero(): void
    {
        [, $reply] = $this->call('ok.json');

        self::assertSame(800, $reply->inputTokens, 'prompt 1200 includes 400 cached: the uncached input is 800');
        self::assertSame(150, $reply->outputTokens);
        self::assertSame(400, $reply->cacheReadTokens);
        self::assertSame(0, $reply->cacheWriteTokens);
        self::assertSame('end', $reply->stopReason);
    }

    public function testFinishReasonsAreNormalised(): void
    {
        self::assertSame('max_tokens', $this->call('max_tokens.json')[1]->stopReason);
        self::assertSame('refusal', $this->call('refusal.json')[1]->stopReason);
    }
}
