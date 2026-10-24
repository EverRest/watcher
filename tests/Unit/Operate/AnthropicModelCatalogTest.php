<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Operate\AnthropicModelCatalog;
use Watcher\Operate\ModelCatalog;

final class AnthropicModelCatalogTest extends TestCase
{
    private const KEY = 'sk-ant-test-SECRET-KEY';

    /** @var list<array<string, mixed>> */
    private array $history = [];

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/anthropic/' . $name);
    }

    private static function ok(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], self::fixture('models-list.json'));
    }

    private static function denied(): Response
    {
        return new Response(401, ['Content-Type' => 'application/json'], self::fixture('error-401.json'));
    }

    /** @param list<Response|\Throwable> $queue */
    private function catalog(array $queue): AnthropicModelCatalog
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new AnthropicModelCatalog(new Client(['handler' => $stack]), self::KEY);
    }

    public function testBothSchemesWorkingAndModelIdsParsed(): void
    {
        $catalog = $this->catalog([self::ok(), self::ok()]);

        self::assertInstanceOf(ModelCatalog::class, $catalog);
        self::assertSame(['bearer' => 200, 'x-api-key' => 200], $catalog->probe());
        self::assertSame(['claude-haiku-4-5-20251001', 'claude-sonnet-4-5-20250929'], $catalog->modelIds());
    }

    public function testBearerRejectedButApiKeyWorks(): void
    {
        $catalog = $this->catalog([self::denied(), self::ok()]);

        self::assertSame(['bearer' => 401, 'x-api-key' => 200], $catalog->probe());
        self::assertCount(2, $catalog->modelIds()); // ids come from the scheme that worked
    }

    public function testBothRejectedGivesNoModels(): void
    {
        $catalog = $this->catalog([self::denied(), self::denied()]);

        self::assertSame(['bearer' => 401, 'x-api-key' => 401], $catalog->probe());
        self::assertSame([], $catalog->modelIds());
    }

    public function testTimeoutIsReportedWithoutTheTransportMessage(): void
    {
        $e = new ConnectException('cURL error 28: timed out https://api.anthropic.com/v1/models?key=' . self::KEY, new Request('GET', 'https://api.anthropic.com/v1/models'));
        $catalog = $this->catalog([$e, $e]);

        $probe = $catalog->probe();

        self::assertSame(['bearer' => 'timeout', 'x-api-key' => 'timeout'], $probe);
        self::assertStringNotContainsString(self::KEY, (string) json_encode($probe));
    }

    /** Guzzle 8 splits timeouts: a read timeout is a NetworkTimeoutException, no longer a ConnectException (which is all 7 has). */
    public function testARealGuzzleReadTimeoutIsReportedAsTimeout(): void
    {
        $guzzle8 = 'GuzzleHttp\\Exception\\NetworkTimeoutException';
        /** @var class-string<\Throwable> $class */
        $class = class_exists($guzzle8) ? $guzzle8 : ConnectException::class;
        $e = new $class('cURL error 28: operation timed out', new Request('GET', 'https://api.anthropic.com/v1/models'));

        self::assertSame(['bearer' => 'timeout', 'x-api-key' => 'timeout'], $this->catalog([$e, $e])->probe());
    }

    public function testRequestsAreGetToTheModelsEndpointWithShortTimeoutAndTheRightHeaders(): void
    {
        $this->catalog([self::ok(), self::ok()])->probe();

        self::assertCount(2, $this->history);
        foreach ($this->history as $h) {
            self::assertSame('GET', $h['request']->getMethod());
            self::assertSame('api.anthropic.com', $h['request']->getUri()->getHost());
            self::assertSame('/v1/models', $h['request']->getUri()->getPath());
            self::assertSame('2023-06-01', $h['request']->getHeaderLine('anthropic-version'));
            self::assertSame(5, $h['options']['timeout']);
            self::assertStringNotContainsString(self::KEY, (string) $h['request']->getUri());
        }
        self::assertSame('Bearer ' . self::KEY, $this->history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('', $this->history[0]['request']->getHeaderLine('x-api-key'));
        self::assertSame(self::KEY, $this->history[1]['request']->getHeaderLine('x-api-key'));
        self::assertSame('', $this->history[1]['request']->getHeaderLine('Authorization'));
    }

    public function testMalformedBodyYieldsNoIdsAndNoLeak(): void
    {
        $catalog = $this->catalog([new Response(200, [], 'not json ' . self::KEY), new Response(200, [], '{"data":"x"}')]);

        $catalog->probe();

        self::assertSame([], $catalog->modelIds());
    }
}
