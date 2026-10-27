<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\AnthropicSdkProvider;

/** The SDK-backed Anthropic adapter: request body equal to the one the old client sent, explicit base URL, retries and timeout. */
final class AnthropicSdkProviderTest extends TestCase
{
    private const KEY = 'sk-ant-test-key';
    private const BASE = 'https://anthropic-gateway.example.test';
    private const SCHEMA = ['type' => 'object', 'properties' => ['findings' => ['type' => 'array']]];

    /**
     * @param list<Response>                           $responses
     * @param \ArrayObject<int, RequestInterface>      $history
     */
    private function provider(array $responses, \ArrayObject $history, int $maxRetries = 0, string $base = self::BASE): AnthropicSdkProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(self::recorder($history));

        return new AnthropicSdkProvider(new Client(['handler' => $stack]), self::KEY, $base, 60, $maxRetries);
    }

    /** Appends every request sent, so the tests can read the wire body. */
    /** @param \ArrayObject<int, RequestInterface> $history */
    private static function first(\ArrayObject $history): RequestInterface
    {
        return $history[0] ?? throw new \LogicException('no request was sent');
    }

    /** @param \ArrayObject<int, RequestInterface> $history */
    private static function recorder(\ArrayObject $history): callable
    {
        return static function (callable $next) use ($history): callable {
            return static function (RequestInterface $request, array $options) use ($next, $history) {
                $history->append($request);

                return $next($request, $options);
            };
        };
    }

    private static function okResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../Fixtures/providers/anthropic/ok.json'));
    }

    public function testRequestBodyMatchesWhatTheOldClientSent(): void
    {
        $history = new \ArrayObject();
        $provider = $this->provider([self::okResponse()], $history);

        $provider->create('claude-model', 800, 'system text', 'user text', self::SCHEMA, false);

        $body = json_decode((string) self::first($history)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('claude-model', $body['model']);
        self::assertSame(800, $body['max_tokens']);
        self::assertSame([['type' => 'text', 'text' => 'system text', 'cache_control' => ['type' => 'ephemeral']]], $body['system']);
        self::assertSame([['role' => 'user', 'content' => 'user text']], $body['messages']);
        self::assertSame(['type' => 'json_schema', 'schema' => self::SCHEMA], $body['output_config']['format']);
        self::assertSame(['type' => 'disabled'], $body['thinking'], 'triage runs with thinking disabled');
    }

    public function testThinkingStaysUnsetWhenEnabled(): void
    {
        $history = new \ArrayObject();
        $provider = $this->provider([self::okResponse()], $history);

        $provider->create('claude-model', 800, 'system text', 'user text', self::SCHEMA, true);

        $body = json_decode((string) self::first($history)->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('thinking', $body);
    }

    public function testConfiguredBaseUrlWinsOverTheEnvironment(): void
    {
        $previous = getenv('ANTHROPIC_BASE_URL');
        putenv('ANTHROPIC_BASE_URL=https://sentinel.invalid');
        try {
            $history = new \ArrayObject();
            $provider = $this->provider([self::okResponse()], $history);

            $provider->create('claude-model', 800, 'system text', 'user text', self::SCHEMA, false);

            self::assertSame(self::BASE, self::first($history)->getUri()->getScheme() . '://' . self::first($history)->getUri()->getHost());
        } finally {
            // putenv('NAME') without a value unsets it
            putenv($previous === false ? 'ANTHROPIC_BASE_URL' : 'ANTHROPIC_BASE_URL=' . $previous);
        }
    }

    public function testZeroRetriesSendsOneRequestOnAServerError(): void
    {
        $history = new \ArrayObject();
        $provider = $this->provider([new Response(500, [], '{}')], $history, 0);

        try {
            $provider->create('claude-model', 800, 'system text', 'user text', self::SCHEMA, false);
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed) {
            self::assertCount(1, $history, 'the SDK must not retry on its own with maxRetries 0');
        }
    }

    public function testConfiguredRetriesReachTheSdk(): void
    {
        $history = new \ArrayObject();
        $provider = $this->provider([new Response(500, [], '{}'), new Response(500, [], '{}'), self::okResponse()], $history, 2);

        $reply = $provider->create('claude-model', 800, 'system text', 'user text', self::SCHEMA, false);

        self::assertNotNull($reply->text);
        self::assertCount(3, $history, 'two retries after the first 500, then success (maxRetries 2)');
    }

    public function testPauseTurnIsNormalised(): void
    {
        $history = new \ArrayObject();
        $provider = $this->provider([new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../Fixtures/anthropic/message-pause-turn.json'))], $history);

        self::assertSame('pause', $provider->create('claude-model', 800, 'system text', 'user text', self::SCHEMA, false)->stopReason);
    }

    public function testOverloadedNamesTheStatus(): void
    {
        $history = new \ArrayObject();
        $provider = $this->provider([new Response(529, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../Fixtures/anthropic/error-529.json'))], $history);

        try {
            $provider->create('claude-model', 800, 'system text', 'user text', self::SCHEMA, false);
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame('provider unavailable (HTTP 529)', $e->getMessage());
        }
    }

    public function testAnOversizedReplyIsRefusedBeforeTheSdkParsesIt(): void
    {
        $history = new \ArrayObject();
        $provider = $this->provider([new Response(200, ['Content-Type' => 'application/json'], str_repeat(' ', \Watcher\Source\ResponseLimit::MAX_BYTES + 1))], $history);

        try {
            $provider->create('claude-model', 800, 'system text', 'user text', self::SCHEMA, false);
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame('provider unavailable (invalid reply)', $e->getMessage());
        }
    }

    public function testANegativeUsageCountIsAnInvalidReply(): void
    {
        $history = new \ArrayObject();
        $body = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/anthropic/message-valid.json'), true, 512, JSON_THROW_ON_ERROR);
        $body['usage']['input_tokens'] = -5;
        $provider = $this->provider([new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR))], $history);

        $this->expectException(AnalyzerFailed::class);
        $this->expectExceptionMessage('provider unavailable (invalid reply)');
        $provider->create('claude-model', 800, 'system text', 'user text', self::SCHEMA, false);
    }
}
