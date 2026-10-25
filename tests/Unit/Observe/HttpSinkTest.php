<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Observe\HttpSink;
use Watcher\Observe\HttpTarget;
use Watcher\Observe\SinkFailed;
use Watcher\Source\Retry;

final class HttpSinkTest extends TestCase
{
    private const URL = 'https://logs.example.com/ingest/URLSECRET';
    private const TOKEN = 'TOKENSECRET123';

    /** @var array<int, array{request: Request, options: array<string, mixed>}> */
    private array $history = [];
    /** @var list<int> */
    private array $slept = [];

    /** @param list<Response|\Throwable> $queue */
    private function sink(array $queue, int $batch = 100, ?string $token = self::TOKEN, string $header = 'Authorization', string $scheme = 'Bearer', ?callable $now = null): HttpSink
    {
        $this->history = [];
        $this->slept = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history)); // @phpstan-ignore assign.propertyType
        $retry = new Retry(function (int $s): void {
            $this->slept[] = $s;
        }, 15);

        return new HttpSink(HttpTarget::parse(self::URL, [], false, false, null), new Client(['handler' => $stack]), $retry, $batch, 5, $header, $scheme, $token, 15, $now);
    }

    /** @return list<array<string, mixed>> */
    private static function events(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; ++$i) {
            $out[] = ['event_id' => "e$i", 'url' => 'a/b', 'text' => 'é'];
        }

        return $out;
    }

    public function testPostsNdjsonWithHeadersAndOptions(): void
    {
        $this->sink([new Response(200)])->emit(self::events(2));

        self::assertCount(1, $this->history);
        $req = $this->history[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertSame(self::URL, (string) $req->getUri());
        self::assertSame('application/x-ndjson', $req->getHeaderLine('Content-Type'));
        self::assertSame('Bearer ' . self::TOKEN, $req->getHeaderLine('Authorization'));
        self::assertSame("{\"event_id\":\"e1\",\"url\":\"a/b\",\"text\":\"é\"}\n{\"event_id\":\"e2\",\"url\":\"a/b\",\"text\":\"é\"}\n", (string) $req->getBody());
        $o = $this->history[0]['options'];
        self::assertFalse($o['allow_redirects']);
        self::assertFalse($o['http_errors']);
        self::assertSame(5, $o['timeout']);
    }

    public function testAuthVariants(): void
    {
        $this->sink([new Response(200)], header: 'X-API-Key', scheme: '')->emit(self::events(1));
        self::assertSame(self::TOKEN, $this->history[0]['request']->getHeaderLine('X-API-Key'));

        $this->sink([new Response(200)], token: null)->emit(self::events(1));
        self::assertFalse($this->history[0]['request']->hasHeader('Authorization'));
    }

    public function testBatchesAtBatchSizeAndSkipsEmpty(): void
    {
        $this->sink([new Response(200), new Response(200), new Response(200)], 2)->emit(self::events(5));
        self::assertCount(3, $this->history);
        self::assertSame(2, substr_count((string) $this->history[0]['request']->getBody(), "\n"));
        self::assertSame(1, substr_count((string) $this->history[2]['request']->getBody(), "\n"));

        $this->sink([])->emit([]);
        self::assertCount(0, $this->history);
    }

    public function testRetriesTransientStatusThenSucceeds(): void
    {
        $this->sink([new Response(503), new Response(429), new Response(200)])->emit(self::events(1));
        self::assertCount(3, $this->history);
        self::assertSame([1, 1], $this->slept);
    }

    public function testRetriesNetworkErrorThenSucceeds(): void
    {
        $this->sink([new ConnectException('boom ' . self::URL, new Request('POST', self::URL)), new Response(204)])->emit(self::events(1));
        self::assertCount(2, $this->history);
    }

    public function testClientErrorsAndRedirectsAreNotRetried(): void
    {
        foreach ([401, 404, 302] as $status) {
            try {
                $this->sink([new Response($status), new Response(200)])->emit(self::events(1));
                self::fail('expected SinkFailed');
            } catch (SinkFailed $e) {
                self::assertSame("http status $status", $e->getMessage());
                self::assertCount(1, $this->history);
            }
        }
    }

    public function testExhaustedRetriesGiveStatusOnly(): void
    {
        try {
            $this->sink([new Response(500), new Response(500), new Response(500)])->emit(self::events(1));
            self::fail('expected SinkFailed');
        } catch (SinkFailed $e) {
            self::assertSame('http status 500', $e->getMessage());
            self::assertCount(1, $this->history); // 500 is not transient for the sink
        }
        try {
            $this->sink([new Response(503), new Response(503), new Response(503)])->emit(self::events(1));
            self::fail('expected SinkFailed');
        } catch (SinkFailed $e) {
            self::assertSame('http status 503', $e->getMessage());
            self::assertCount(3, $this->history);
        }
    }

    /** SC-008 */
    public function testNoFailurePathLeaksUrlOrToken(): void
    {
        $req = new Request('POST', self::URL, ['Authorization' => 'Bearer ' . self::TOKEN]);
        $leaky = static fn (string $m): ConnectException => new ConnectException($m . ' ' . self::URL . ' ' . self::TOKEN, $req);
        $paths = [
            'dns' => [$leaky('cURL error 6: Could not resolve host'), $leaky('x'), $leaky('x')],
            'tls' => [$leaky('cURL error 60: SSL certificate problem'), $leaky('x'), $leaky('x')],
            'timeout' => [$leaky('cURL error 28: timed out'), $leaky('x'), $leaky('x')],
            '401' => [new Response(401, [], self::TOKEN)],
            '500' => [new Response(500, [], self::URL)],
        ];
        foreach ($paths as $name => $queue) {
            $name = (string) $name;
            try {
                $this->sink($queue)->emit(self::events(1));
                self::fail("$name: expected SinkFailed");
            } catch (SinkFailed $e) {
                self::assertNull($e->getPrevious(), $name);
                foreach ([$e->getMessage(), (string) $e] as $text) {
                    self::assertStringNotContainsString('URLSECRET', $text, $name);
                    self::assertStringNotContainsString('logs.example.com', $text, $name);
                    self::assertStringNotContainsString(self::TOKEN, $text, $name);
                }
            }
        }
    }

    public function testNetworkFailureMessageIsFixed(): void
    {
        $c = new ConnectException('x', new Request('POST', self::URL));
        $this->expectException(SinkFailed::class);
        $this->expectExceptionMessage('http request failed');
        $this->sink([$c, $c, $c])->emit(self::events(1));
    }

    public function testUnencodableEventSendsNothingAndIsFixedMessage(): void
    {
        $events = [...self::events(1), ['event_id' => "bad\xff"]];
        try {
            $this->sink([new Response(200), new Response(200)], 1)->emit($events);
            self::fail('expected SinkFailed');
        } catch (SinkFailed $e) {
            self::assertSame('http request failed', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertCount(0, $this->history);
        }
    }

    public function testFlushBudgetStopsBeforeLaterBatches(): void
    {
        $t = [0.0, 16.0];
        $clock = static function () use (&$t): float {
            return array_shift($t) ?? 99.0;
        };
        try {
            $this->sink([new Response(200), new Response(200)], 1, now: $clock)->emit(self::events(2));
            self::fail('expected SinkFailed');
        } catch (SinkFailed $e) {
            self::assertSame('http flush budget exceeded', $e->getMessage());
            self::assertCount(1, $this->history);
        }
    }

    public function testFlushWithinBudgetSendsAll(): void
    {
        $t = [0.0, 15.0];
        $this->sink([new Response(200), new Response(200)], 1, now: static function () use (&$t): float {
            return array_shift($t) ?? 99.0;
        })->emit(self::events(2));
        self::assertCount(2, $this->history);
    }

    public function testEveryRequestCarriesTheResponseLimitOptions(): void
    {
        $this->sink([new Response(200)])->emit(self::events(1));

        $o = $this->history[0]['options'];
        self::assertInstanceOf(\Closure::class, $o['on_headers']);
        self::assertTrue($o['decode_content']);
    }

    public function testAnOversizedDeclaredBodyAbortsAtTheHeaders(): void
    {
        $sink = $this->sink([new Response(200, ['Content-Length' => (string) (\Watcher\Source\ResponseLimit::MAX_BYTES + 1)])]);

        try {
            $sink->emit(self::events(1));
            self::fail('expected SinkFailed');
        } catch (SinkFailed $e) {
            self::assertSame('http request failed', $e->getMessage());
        }
    }

    public function testWithinCapsTheRequestTimeoutToWhatIsLeftOfTheRun(): void
    {
        $sink = $this->sink([new Response(200)]);
        $sink->within(3);
        $sink->emit(self::events(1));

        self::assertSame(3, $this->history[0]['options']['timeout']);
    }

    public function testRetriesStopOnceTheFlushBudgetIsSpent(): void
    {
        $t = [0.0, 20.0]; // the start of the flush, then the clock the second attempt sees
        $sink = $this->sink([new ConnectException('x', new Request('POST', 'https://logs.example.com/')), new Response(200)], now: static function () use (&$t): float {
            return array_shift($t) ?? 99.0;
        });

        try {
            $sink->emit(self::events(1));
            self::fail('expected SinkFailed');
        } catch (SinkFailed $e) {
            self::assertSame('http request failed', $e->getMessage());
            self::assertCount(1, $this->history, 'the second attempt never started');
        }
    }

    /**
     * @param list<Response|\Throwable> $queue
     * @param list<int> $slept
     */
    private function boundedSink(array $queue, array &$slept, int $within, ?callable $now = null): HttpSink
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history)); // @phpstan-ignore assign.propertyType
        $sink = new HttpSink(HttpTarget::parse(self::URL, [], false, false, null), new Client(['handler' => $stack]), static function (int $left, callable $sleeper) use (&$slept): Retry {
            return new Retry(static function (int $s) use (&$slept, $sleeper): void {
                $slept[] = $s;
                $sleeper($s);
            }, min(15, $left));
        }, 100, 5, 'Authorization', 'Bearer', self::TOKEN, 15, $now);
        $sink->within($within);

        return $sink;
    }

    public function testARetryAfterLongerThanTheRunDeadlineIsNeverSlept(): void
    {
        $slept = [];
        $sink = $this->boundedSink([new Response(429, ['Retry-After' => '15'])], $slept, 2);

        try {
            $sink->emit(self::events(1));
            self::fail('expected SinkFailed');
        } catch (SinkFailed $e) {
            self::assertSame('http status 429', $e->getMessage());
        }
        self::assertSame([], $slept, 'the 15 s Retry-After does not fit in the 2 s that are left');
        self::assertCount(1, $this->history);
    }

    public function testTotalSleepNeverExceedsTheBudget(): void
    {
        foreach ([1 => [1], 3 => [1], 4 => [1, 3], 15 => [1, 3]] as $budget => $expected) {
            $slept = [];
            $sink = $this->boundedSink([new Response(503), new Response(503), new Response(503)], $slept, $budget);
            try {
                $sink->emit(self::events(1));
                self::fail('expected SinkFailed');
            } catch (SinkFailed) {
                $this->addToAssertionCount(1);
            }
            self::assertSame($expected, $slept, "budget $budget");
            self::assertLessThanOrEqual($budget, array_sum($slept));
        }
    }

    public function testALateAttemptGetsOnlyWhatIsLeftAsItsTimeout(): void
    {
        $slept = [];
        $t = [0.0, 0.0, 10.0]; // start, the guard before the retry wait, then the clock the second attempt sees
        $sink = $this->boundedSink([new ConnectException('x', new Request('POST', 'https://logs.example.com/')), new Response(200)], $slept, 15, static function () use (&$t): float {
            return array_shift($t) ?? 99.0;
        });
        $sink->emit(self::events(1));

        self::assertSame(5, $this->history[1]['options']['timeout'], 'min(5 s timeout, 5 s left)');
        self::assertSame(5, $this->history[0]['options']['timeout']);
    }

    public function testNoWaitOrAttemptStartsThatCannotFinishInsideTheBudget(): void
    {
        $t = 0.0;
        $now = static function () use (&$t): float {
            return $t;
        };
        $attempts = 0;
        $handler = static function ($request, array $options) use (&$t, &$attempts) {
            ++$attempts;
            $t += $options['timeout']; // every attempt runs into its timeout
            throw new ConnectException('timeout', $request);
        };
        $stack = HandlerStack::create(new MockHandler([$handler, $handler, $handler, $handler]));
        $waits = [];
        $sink = new HttpSink(
            HttpTarget::parse(self::URL, [], false, false, null),
            new Client(['handler' => $stack]),
            static fn (int $left, callable $sleeper): Retry => new Retry($sleeper, min(15, $left)),
            100,
            5,
            'Authorization',
            'Bearer',
            self::TOKEN,
            15,
            $now,
            static function (int $s) use (&$t, &$waits): void {
                $waits[] = $s;
                $t += $s;
            },
        );
        $sink->within(10);

        try {
            $sink->emit(self::events(1));
            self::fail('expected SinkFailed');
        } catch (SinkFailed) {
            $this->addToAssertionCount(1);
        }

        self::assertLessThanOrEqual(10.0, $t, 'attempts and waits together stay inside the 10 s the run deadline leaves');
        self::assertSame([1], $waits, 'the 3 s wait would end at 13 s and is never started');
        self::assertSame(2, $attempts);
    }
}
