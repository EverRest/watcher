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
        self::assertArrayNotHasKey('thinkingConfig', $body['generationConfig'], 'no thinkingConfig: the reference errors on non-thinking models and does not document 0 as off (research R3)');
    }

    public function testCachedTokensAreReadTokensAndWritesAreZero(): void
    {
        [, $reply] = $this->call('ok.json');

        self::assertSame('end', $reply->stopReason);
    }

    public function testFinishReasonsAreNormalised(): void
    {
        self::assertSame('max_tokens', $this->call('max_tokens.json')[1]->stopReason);
        self::assertSame('refusal', $this->call('refusal.json')[1]->stopReason);
    }

    public function testABlockedPromptIsARefusalWithItsUsage(): void
    {
        $body = ['promptFeedback' => ['blockReason' => 'SAFETY'], 'usageMetadata' => ['promptTokenCount' => 90, 'cachedContentTokenCount' => 0], 'modelVersion' => 'gemini-model'];
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR))]));
        $reply = (new GeminiProvider(new Client(['handler' => $stack]), 'gm-test', 'https://gemini.example.test', 60, 0))
            ->create('gemini-model', 900, 'system text', 'user text', self::SCHEMA, true);

        self::assertSame('refusal', $reply->stopReason);
    }
}
