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
use Watcher\Analyze\GeminiProvider;

/** The Gemini adapter: request shape, cache read tokens, stop reasons. Recorded replies only. */
final class GeminiProviderTest extends TestCase
{
    private const SCHEMA = ['type' => 'object', 'properties' => ['findings' => ['type' => 'array']]];

    /** @return array{0: array<string, mixed>, 1: ModelReply} */
    private function call(string $fixture, bool $thinking = false): array
    {
        /** @var \ArrayObject<int, RequestInterface> $history */
        $history = new \ArrayObject();
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../Fixtures/providers/gemini/' . $fixture))]));
        $stack->push(static function (callable $next) use ($history): callable {
            return static function (RequestInterface $request, array $options) use ($next, $history) {
                $history->append($request);

                return $next($request, $options);
            };
        });
        $reply = (new GeminiProvider(new Client(['handler' => $stack]), 'sk-openai-test', 'https://openai.example.test', 60, 0))
            ->create('gpt-model', 900, 'system text', 'user text', self::SCHEMA, $thinking);

        self::assertCount(1, $history);
        $request = $history[0] ?? throw new \LogicException('no request was sent');
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return [$body, $reply];
    }

    public function testRequestCarriesTheSchemaAsResponseJsonSchemaAndThinkingOff(): void
    {
        [$body] = $this->call('ok.json');

        self::assertSame(['parts' => [['text' => 'system text']]], $body['systemInstruction']);
        self::assertSame([['role' => 'user', 'parts' => [['text' => 'user text']]]], $body['contents']);
        self::assertSame(900, $body['generationConfig']['maxOutputTokens']);
        self::assertSame('application/json', $body['generationConfig']['responseMimeType']);
        self::assertSame(self::SCHEMA, $body['generationConfig']['responseJsonSchema']);
        self::assertSame(['thinkingBudget' => 0], $body['generationConfig']['thinkingConfig'], 'triage turns thinking off; the field is unverified (research R3)');
    }

    public function testThinkingIsLeftToTheModelWhenEnabled(): void
    {
        [$body] = $this->call('ok.json', true);

        self::assertArrayNotHasKey('thinkingConfig', $body['generationConfig']);
    }

    public function testCachedTokensAreReadTokensAndWritesAreZero(): void
    {
        [, $reply] = $this->call('ok.json');

        self::assertSame(1200, $reply->inputTokens);
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
