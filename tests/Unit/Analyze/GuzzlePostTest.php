<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\GuzzlePost;

/** The shared POST: the reply it returns, the failure names it gives, and the retry it does with the fake clock. */
final class GuzzlePostTest extends TestCase
{
    private float $now = 0.0;

    /** @var list<int> */
    private array $slept = [];

    /**
     * @param list<Response|ConnectException> $replies
     *
     * @return array{0: int, 1: array<mixed>}
     */
    private function post(array $replies, int $retries = 0, float $timeout = 60.0): array
    {
        $http = new Client(['handler' => HandlerStack::create(new MockHandler($replies))]);

        return GuzzlePost::json(
            $http,
            'https://provider.example.test/v1',
            ['x-test' => 'yes'],
            ['prompt' => 'user text'],
            $timeout,
            'provider',
            $retries,
            function (int $seconds): void {
                $this->slept[] = $seconds;
                $this->now += $seconds;
            },
            fn (): float => $this->now,
        );
    }

    public function testA200ReturnsItsStatusAndDecodedBody(): void
    {
        [$status, $data] = $this->post([new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}')]);

        self::assertSame(200, $status);
        self::assertSame(['ok' => true], $data);
    }

    public function testANonJsonReplyIsAnInvalidReply(): void
    {
        $this->expectException(AnalyzerFailed::class);
        $this->expectExceptionMessage('provider unavailable (invalid reply)');
        $this->post([new Response(200, ['Content-Type' => 'application/json'], 'not json')]);
    }

    public function testAnUnretriedStatusNamesOnlyTheStatus(): void
    {
        try {
            $this->post([new Response(404, [], '{"secret":"sk-leak"}')]);
            self::fail('HTTP 404 must fail');
        } catch (AnalyzerFailed $e) {
            self::assertSame('provider unavailable (HTTP 404)', $e->getMessage());
            self::assertStringNotContainsString('sk-leak', $e->getMessage());
        }
    }

    public function testAnExhaustedTransportFailureNamesTheExceptionClassOnly(): void
    {
        $request = new Request('POST', 'https://provider.example.test/v1?key=sk-leak');
        try {
            $this->post([new ConnectException('cannot connect sk-leak', $request), new ConnectException('cannot connect sk-leak', $request)], 1);
            self::fail('a connect error on every attempt must fail');
        } catch (AnalyzerFailed $e) {
            self::assertSame('provider unavailable (ConnectException)', $e->getMessage());
            self::assertStringNotContainsString('sk-leak', $e->getMessage());
        }
        self::assertSame([1], $this->slept);
    }

    public function testRetriesAreOffByDefault(): void
    {
        try {
            $this->post([new Response(503), new Response(200, [], '{}')]);
            self::fail('no retry without the retries argument');
        } catch (AnalyzerFailed $e) {
            self::assertSame('provider unavailable (HTTP 503)', $e->getMessage());
        }
        self::assertSame([], $this->slept);
    }
}
