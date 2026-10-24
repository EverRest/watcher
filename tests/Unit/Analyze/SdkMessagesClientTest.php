<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\SdkMessagesClient;

/** The rollback path (`analysis.client = sdk`) keeps its old behaviour for one release. */
final class SdkMessagesClientTest extends TestCase
{
    private const KEY = 'sk-ant-test-SECRET-KEY';

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /** @param list<Response> $queue */
    private function client(array $queue): SdkMessagesClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        /** @var list<array{request: \Psr\Http\Message\RequestInterface}> $log */
        $log = [];
        $this->history = &$log; // Guzzle fills it by reference
        $stack->push(Middleware::history($log));

        return new SdkMessagesClient(self::KEY, new Client(['handler' => $stack, 'http_errors' => false]), null, ['initialRetryDelay' => 0.0, 'maxRetryDelay' => 0.0]);
    }

    private static function fixture(string $name, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../Fixtures/anthropic/' . $name));
    }

    public function testMapsAReplyAndSendsTheKeyAsBearer(): void
    {
        $r = $this->client([self::fixture('message-valid.json')])->create('m', 100, 'sys', 'user', ['type' => 'object'], true);

        self::assertSame('Bearer ' . self::KEY, $this->history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('/v1/messages', $this->history[0]['request']->getUri()->getPath());
        self::assertSame([1200, 150, 400, 800, 'end_turn'], [$r->inputTokens, $r->outputTokens, $r->cacheReadTokens, $r->cacheWriteTokens, $r->stopReason]);
    }

    public function testAuthenticationFailureHidesTheKey(): void
    {
        try {
            $this->client([self::fixture('error-401.json', 401)])->create('m', 100, 'sys', 'user', [], true);
            self::fail('expected AnalyzerFailed');
        } catch (AnalyzerFailed $e) {
            self::assertSame('authentication failed', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString(self::KEY, (string) $e);
        }
    }

    public function testServerErrorsAreProviderUnavailableAfterSdkRetries(): void
    {
        $this->expectExceptionObject(new AnalyzerFailed('provider unavailable'));
        try {
            $this->client([self::fixture('error-529.json', 529), self::fixture('error-529.json', 529), self::fixture('error-529.json', 529)])->create('m', 100, 'sys', 'user', [], true);
        } finally {
            self::assertCount(3, $this->history);
        }
    }
}
