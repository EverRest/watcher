<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\AnthropicHttpClient;
use Watcher\Analyze\ModelReply;
use Watcher\Source\ResponseLimit;

final class AnthropicHttpClientTest extends TestCase
{
    private const KEY = 'sk-ant-test-SECRET-KEY';

    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    private static function fixture(string $name, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../Fixtures/anthropic/' . $name));
    }

    /** @param list<Response|\Throwable> $queue */
    private function client(array $queue): AnthropicHttpClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> $log */
        $log = [];
        $this->history = &$log; // Guzzle fills it by reference
        $stack->push(Middleware::history($log));

        return new AnthropicHttpClient(self::KEY, new Client(['handler' => $stack]));
    }

    private function call(AnthropicHttpClient $c, bool $thinking = true): ModelReply
    {
        return $c->create('model-x', 321, 'SYSTEM RULES', 'USER DATA', ['type' => 'object'], $thinking);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $b = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertIsArray($b);

        return $b;
    }

    public function testRequestShape(): void
    {
        $this->call($this->client([self::fixture('message-valid.json')]));

        self::assertCount(1, $this->history);
        $req = $this->history[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://api.anthropic.com/v1/messages', (string) $req->getUri());
        self::assertSame(self::KEY, $req->getHeaderLine('x-api-key'));
        self::assertSame('2023-06-01', $req->getHeaderLine('anthropic-version'));
        self::assertFalse($req->hasHeader('Authorization'));

        $b = $this->body();
        self::assertSame('model-x', $b['model']);
        self::assertSame(321, $b['max_tokens']);
        self::assertSame([['type' => 'text', 'text' => 'SYSTEM RULES', 'cache_control' => ['type' => 'ephemeral']]], $b['system']);
        self::assertSame([['role' => 'user', 'content' => 'USER DATA']], $b['messages']);
        self::assertSame(['format' => ['type' => 'json_schema', 'schema' => ['type' => 'object']]], $b['output_config']);
        self::assertArrayNotHasKey('thinking', $b, 'analysis leaves thinking at the API default');
        self::assertArrayNotHasKey('tools', $b);
        self::assertArrayNotHasKey('stream', $b);
    }

    public function testTriageDisablesThinking(): void
    {
        $this->call($this->client([self::fixture('message-valid.json')]), false);

        self::assertSame(['type' => 'disabled'], $this->body()['thinking']);
    }

    public function testNoRedirectsTlsVerifiedAndBodyBounded(): void
    {
        $this->call($this->client([self::fixture('message-valid.json')]));

        $o = $this->history[0]['options'];
        self::assertFalse($o['allow_redirects']);
        self::assertTrue($o['verify']);
        self::assertGreaterThan(0, $o['timeout']);
        self::assertGreaterThan(0, $o['connect_timeout']);
        self::assertArrayHasKey('on_headers', $o, 'ResponseLimit is applied');
    }

    public function testEnvironmentCannotRedirectTheKey(): void
    {
        $old = getenv('ANTHROPIC_BASE_URL');
        putenv('ANTHROPIC_BASE_URL=https://evil.example');
        try {
            $this->call($this->client([self::fixture('message-valid.json')]));
        } finally {
            $old === false ? putenv('ANTHROPIC_BASE_URL') : putenv('ANTHROPIC_BASE_URL=' . $old);
        }

        self::assertSame('api.anthropic.com', $this->history[0]['request']->getUri()->getHost());
    }

    public function testSuccessMapsTextUsageAndStop(): void
    {
        $r = $this->call($this->client([self::fixture('message-valid.json')]));

        self::assertStringStartsWith('{"findings"', (string) $r->text);
        self::assertSame('end_turn', $r->stopReason);
        self::assertSame('claude-fixture-model-1', $r->model);
        self::assertSame(1200, $r->inputTokens);
        self::assertSame(150, $r->outputTokens);
        self::assertSame(400, $r->cacheReadTokens);
        self::assertSame(800, $r->cacheWriteTokens);
    }

    public function testRefusalHasNoTextAndIsReportedAsRefusal(): void
    {
        $r = $this->call($this->client([self::fixture('message-refusal.json')]));

        self::assertSame('refusal', $r->stopReason);
        self::assertNull($r->text);
        self::assertSame(700, $r->inputTokens, 'a refused call is still billed');
    }

    /** @dataProvider stopReasons */
    public function testStopReasons(string $fixture, string $expected): void
    {
        self::assertSame($expected, $this->call($this->client([self::fixture($fixture)]))->stopReason);
    }

    /** @return array<string, array{string, string}> */
    public static function stopReasons(): array
    {
        return ['max_tokens' => ['message-max-tokens.json', 'max_tokens'], 'pause_turn' => ['message-pause-turn.json', 'pause_turn']];
    }

    public function testAnUnknownStopReasonIsNull(): void
    {
        $body = (string) json_encode(['model' => 'm', 'content' => [], 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);

        self::assertNull($this->call($this->client([new Response(200, [], $body)]))->stopReason);
    }

    /** @dataProvider authStatuses */
    public function testAuthenticationFailureHidesTheKey(int $status): void
    {
        try {
            $this->call($this->client([self::fixture('error-401.json', $status)]));
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame('authentication failed', $e->getMessage());
            self::assertNull($e->usage);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString(self::KEY, (string) $e);
            self::assertStringNotContainsString('invalid x-api-key', (string) $e, 'the reply body is not repeated');
        }
        self::assertCount(1, $this->history, 'not retried');
    }

    /** @return array<string, array{int}> */
    public static function authStatuses(): array
    {
        return ['HTTP 401' => [401], 'HTTP 403' => [403]];
    }

    /** @dataProvider failingStatuses */
    public function testServerSideFailuresAreOneRequestAndNameOnlyTheCode(int $status, string $fixture): void
    {
        try {
            $this->call($this->client([self::fixture($fixture, $status), self::fixture('message-valid.json')]));
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame("provider unavailable (HTTP $status)", $e->getMessage());
            self::assertNull($e->usage);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString(self::KEY, (string) $e);
            self::assertStringNotContainsString('api.anthropic.com', (string) $e);
        }
        self::assertCount(1, $this->history, 'the client never retries');
    }

    /** @return array<string, array{int, string}> */
    public static function failingStatuses(): array
    {
        return ['rate limited' => [429, 'error-429.json'], 'server error' => [500, 'error-500.json'], 'overloaded' => [529, 'error-529.json']];
    }

    public function testARedirectIsAFailureNotFollowed(): void
    {
        $this->expectExceptionObject(new AnalyzerFailed('provider unavailable (HTTP 302)'));
        try {
            $this->call($this->client([new Response(302, ['Location' => 'https://evil.example/'])]));
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    public function testTransportFailureNamesOnlyTheClass(): void
    {
        $leaky = new ConnectException('cURL error 6: Could not resolve host api.anthropic.com (key ' . self::KEY . ')', new Request('POST', 'https://api.anthropic.com/v1/messages'));

        try {
            $this->call($this->client([$leaky]));
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame('provider unavailable (ConnectException)', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString(self::KEY, (string) $e);
        }
    }

    public function testANonJsonBodyIsProviderUnavailable(): void
    {
        $html = new Response(200, ['Content-Type' => 'text/html'], (string) file_get_contents(__DIR__ . '/../../Fixtures/anthropic/body-not-json.html'));

        $this->expectExceptionObject(new AnalyzerFailed('provider unavailable (invalid reply)'));
        $this->call($this->client([$html]));
    }

    public function testAReplyWithoutUsageIsInvalid(): void
    {
        $this->expectExceptionObject(new AnalyzerFailed('provider unavailable (invalid reply)'));
        $this->call($this->client([new Response(200, [], '{"model":"m","content":[],"stop_reason":"end_turn"}')]));
    }

    /** @dataProvider badCacheFields */
    public function testNonIntegerCacheTokensAreAnInvalidReply(mixed $value): void
    {
        $body = (string) json_encode(['model' => 'm', 'content' => [], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'cache_read_input_tokens' => $value]]);

        $this->expectExceptionObject(new AnalyzerFailed('provider unavailable (invalid reply)'));
        $this->call($this->client([new Response(200, [], $body)]));
    }

    /** @return array<string, array{mixed}> */
    public static function badCacheFields(): array
    {
        return ['string' => ['12'], 'negative' => [-5], 'array' => [[1]]];
    }

    public function testAnOversizedReplyIsRefusedBeforeParsing(): void
    {
        $big = new Response(200, ['Content-Length' => (string) (ResponseLimit::MAX_BYTES + 1)], '{}');

        $this->expectException(AnalyzerFailed::class);
        $this->expectExceptionMessageMatches('/^provider unavailable/');
        $this->call($this->client([$big]));
    }
}
