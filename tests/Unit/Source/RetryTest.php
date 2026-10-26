<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Watcher\Source\Retry;
use Watcher\Source\SourceUnavailable;

final class RetryTest extends TestCase
{
    /** @var list<int> */
    private array $slept = [];
    private int $calls = 0;

    private function retry(int $budget = 60): Retry
    {
        $this->slept = [];
        $this->calls = 0;

        return new Retry(function (int $s): void {
            $this->slept[] = $s;
        }, $budget);
    }

    /** @param list<ResponseInterface|\Throwable> $queue */
    private function sender(array $queue): \Closure
    {
        return function () use (&$queue): ResponseInterface {
            ++$this->calls;
            $next = array_shift($queue) ?? new Response(200);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        };
    }

    public function testACutReplyIsNotRequestedAgain(): void
    {
        $retry = $this->retry();
        $tripped = new \ArrayObject(['large' => true]); // the sink refused the reply: the transfer ended with a Guzzle exception
        try {
            $retry->send(function (): ResponseInterface {
                ++$this->calls;

                throw new \GuzzleHttp\Exception\RequestException('cURL error 23', new Request('GET', 'http://x/'));
            }, 'test', true, $tripped);
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertTrue($e->tooLarge);
        }
        self::assertSame(1, $this->calls);
        self::assertSame([], $this->slept);
    }

    public function testNetworkFailureDoesNotChainTheGuzzleExceptionWhichCarriesTheUrl(): void
    {
        $url = 'https://api.telegram.org/botSECRET-TOKEN/sendMessage';
        $fail = static fn (): ConnectException => new ConnectException("cURL error 6: Could not resolve host for {$url}", new Request('POST', $url));

        foreach ([
            fn (): ResponseInterface => $this->retry(0)->send($this->sender([$fail(), $fail(), $fail()]), 'x', false),
            fn (): ResponseInterface => $this->retry()->sendRateLimited($this->sender([$fail()]), 'x'),
        ] as $call) {
            try {
                $call();
                self::fail('expected SourceUnavailable');
            } catch (SourceUnavailable $e) {
                self::assertNull($e->getPrevious());
                self::assertSame('x request failed (' . ConnectException::class . ')', $e->getMessage());
            }
        }
    }

    /** Guzzle 7 asks RequestException::hasResponse(), Guzzle 8 has no such method: the check must hold on both. */
    public function testAFailureThatCarriesAResponseIsNotRetried(): void
    {
        $request = new Request('POST', 'https://x.invalid');
        $failure = new ServerException('boom', $request, new Response(500));
        $retry = $this->retry();

        try {
            $retry->send($this->sender([$failure, new Response(200)]), 'x', false);
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable) {
            self::assertSame(1, $this->calls);
            self::assertSame([], $this->slept);
        }
    }

    public function testRetriesA429WithTheHint(): void
    {
        $hint = static fn (ResponseInterface $r): float => (float) json_decode((string) $r->getBody(), true)['retry_after'];
        $r = $this->retry()->sendRateLimited($this->sender([new Response(429, [], '{"retry_after":2}'), new Response(200)]), 'x', $hint);

        self::assertSame(200, $r->getStatusCode());
        self::assertSame([2], $this->slept);
    }

    public function testHintBeatsRetryAfterHeader(): void
    {
        $this->retry()->sendRateLimited($this->sender([new Response(429, ['Retry-After' => '9']), new Response(200)]), 'x', static fn (): float => 3.0);

        self::assertSame([3], $this->slept);
    }

    public function testHeaderUsedWhenHintIsNull(): void
    {
        $this->retry()->sendRateLimited($this->sender([new Response(429, ['Retry-After' => '4']), new Response(200)]), 'x', static fn (): ?float => null);

        self::assertSame([4], $this->slept);
    }

    public function testFractionalHintRoundsUp(): void
    {
        $this->retry()->sendRateLimited($this->sender([new Response(429), new Response(200)]), 'x', static fn (): float => 0.2);

        self::assertSame([1], $this->slept);
    }

    public function testOnlyA429IsRetried(): void
    {
        foreach ([500, 502, 503, 504, 403] as $status) {
            $r = $this->retry()->sendRateLimited($this->sender([new Response($status), new Response(200)]), 'x');

            self::assertSame($status, $r->getStatusCode());
            self::assertSame(1, $this->calls);
            self::assertSame([], $this->slept);
        }
    }

    public function testTransportErrorThrowsAtOnce(): void
    {
        $this->expectException(SourceUnavailable::class);
        try {
            $this->retry()->sendRateLimited($this->sender([new ConnectException('boom', new Request('POST', 'https://x.invalid')), new Response(200)]), 'x');
        } finally {
            self::assertSame(1, $this->calls);
            self::assertSame([], $this->slept);
        }
    }

    public function testGivesUpAfterThreeAttemptsAndReturnsThe429(): void
    {
        $r = $this->retry()->sendRateLimited($this->sender([new Response(429), new Response(429), new Response(429)]), 'x', static fn (): float => 1.0);

        self::assertSame(429, $r->getStatusCode());
        self::assertSame(3, $this->calls);
        self::assertSame([1, 1], $this->slept);
    }

    public function testSingleWaitIsCappedAt30Seconds(): void
    {
        $this->retry(120)->sendRateLimited($this->sender([new Response(429), new Response(200)]), 'x', static fn (): float => 45.0);

        self::assertSame([30], $this->slept);
    }

    public function testHintAboveRemainingBudgetFailsFastWithoutSleeping(): void
    {
        $r = $this->retry(60)->sendRateLimited($this->sender([new Response(429), new Response(200)]), 'x', static fn (): float => 600.0);

        self::assertSame(429, $r->getStatusCode());
        self::assertSame(1, $this->calls);
        self::assertSame([], $this->slept);
    }

    /** @return array<string, array{string, mixed}> */
    public static function hugeWaits(): array
    {
        return [
            'Retry-After beyond float range' => ['1e400', null],
            'Retry-After huge digits' => ['99999999999999999999999', null],
            'hint beyond int range' => ['', PHP_INT_MAX],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hugeWaits')]
    public function testAHugeRemoteWaitGivesUpWithoutACastWarning(string $header, mixed $hint): void
    {
        set_error_handler(static function (int $no, string $message): never {
            throw new \ErrorException($message, 0, $no); // a cast of an out-of-range number warns
        });
        try {
            $r = $this->retry(60)->sendRateLimited(
                $this->sender([new Response(429, $header === '' ? [] : ['Retry-After' => $header]), new Response(200)]),
                'x',
                static fn (): int|null => $hint,
            );
        } finally {
            restore_error_handler();
        }

        self::assertSame(429, $r->getStatusCode());
        self::assertSame([], $this->slept);
    }

    public function testBudgetIsSharedAcrossCallsOnOneInstance(): void
    {
        $retry = $this->retry(10);
        $hint = static fn (): float => 6.0;
        $first = $retry->sendRateLimited($this->sender([new Response(429), new Response(200)]), 'x', $hint);
        $second = $retry->sendRateLimited($this->sender([new Response(429), new Response(200)]), 'x', $hint);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode(), 'second part finds 6 s of 10 s already spent');
        self::assertSame([6], $this->slept);
    }

    public function testSendStillRetriesServerErrorsAndResetsItsBudget(): void
    {
        $r = $this->retry()->send($this->sender([new Response(503), new Response(200)]), 'x', false);

        self::assertSame(200, $r->getStatusCode());
        self::assertSame([1], $this->slept);
    }

    public function testRetryAfterHttpDateIsHonoured(): void
    {
        $when = gmdate('D, d M Y H:i:s \G\M\T', time() + 4);
        $this->retry()->sendRateLimited($this->sender([new Response(429, ['Retry-After' => $when]), new Response(200)]), 'x', static fn (): ?float => null);

        self::assertSame([4], $this->slept);
    }

    public function testUnrecognisedRetryAfterFallsBackToOneSecond(): void
    {
        $this->retry()->sendRateLimited($this->sender([new Response(429, ['Retry-After' => 'not-a-date']), new Response(200)]), 'x', static fn (): ?float => null);

        self::assertSame([1], $this->slept);
    }
}
