<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Watcher\Analyze\AnalyzerFailed;
use Watcher\Analyze\GeminiProvider;
use Watcher\Analyze\ModelProvider;
use Watcher\Analyze\OpenAiProvider;

/**
 * Transport retry of the Gemini and OpenAI adapters: transient failures are retried within the call's timeout, nothing else.
 * Time is fake: the sleeper advances the clock, and each attempt costs `$cost` seconds, so no test sleeps.
 */
final class ProviderRetryTest extends TestCase
{
    private const SCHEMA = ['type' => 'object'];
    private const TIMEOUT = 10;

    private float $now = 0.0;

    /** @var list<int> */
    private array $slept = [];

    /** @var list<float> the Guzzle timeout option of each attempt */
    private array $timeouts = [];

    /** @return array<string, array{0: string}> */
    public static function providers(): array
    {
        return ['gemini' => ['gemini'], 'openai' => ['openai']];
    }

    /** @param list<Response|ConnectException> $replies */
    private function provider(string $name, array $replies, int $retries, float $cost = 0.0): ModelProvider
    {
        $stack = HandlerStack::create(new MockHandler($replies));
        $stack->push(function (callable $next) use ($cost): callable {
            return function (RequestInterface $request, array $options) use ($next, $cost) {
                $this->timeouts[] = (float) $options['timeout'];
                $this->now += $cost;

                return $next($request, $options);
            };
        });
        $http = new Client(['handler' => $stack]);
        $sleeper = function (int $seconds): void {
            $this->slept[] = $seconds;
            $this->now += $seconds;
        };
        $now = fn (): float => $this->now;

        return match ($name) {
            'gemini' => new GeminiProvider($http, 'gm-test', 'https://gemini.example.test', self::TIMEOUT, $retries, $sleeper, $now),
            'openai' => new OpenAiProvider($http, 'sk-test', 'https://openai.example.test', self::TIMEOUT, $retries, $sleeper, $now),
            default => throw new \LogicException("no adapter for $name"),
        };
    }

    private function ask(ModelProvider $provider): void
    {
        $provider->create('model-under-test', 900, 'system text', 'user text', self::SCHEMA, false);
    }

    private static function ok(string $name): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) file_get_contents(__DIR__ . '/../../Fixtures/providers/' . $name . '/ok.json'));
    }

    private static function httpError(int $status, string $retryAfter = ''): Response
    {
        return new Response($status, $retryAfter === '' ? [] : ['Retry-After' => $retryAfter], '{"error":"nope"}');
    }

    private static function timeout(): ConnectException
    {
        return new ConnectException('timed out', new Request('POST', 'https://provider.example.test'));
    }

    #[DataProvider('providers')]
    public function testA503IsRetriedAndTheSecondAttemptSucceeds(string $name): void
    {
        $this->ask($this->provider($name, [self::httpError(503), self::ok($name)], 1));

        self::assertSame([1], $this->slept, 'one backoff wait before the second attempt');
    }

    #[DataProvider('providers')]
    public function testARetryAfterHeaderSetsTheWait(string $name): void
    {
        $this->ask($this->provider($name, [self::httpError(429, '5'), self::ok($name)], 1));

        self::assertSame([5], $this->slept);
    }

    #[DataProvider('providers')]
    public function testA429ForeverFailsWithTheOriginalStatusAfterRetriesPlusOneAttempts(string $name): void
    {
        $provider = $this->provider($name, [self::httpError(429), self::httpError(429), self::httpError(429)], 2);

        try {
            $this->ask($provider);
            self::fail('a 429 on every attempt must fail');
        } catch (AnalyzerFailed $e) {
            self::assertSame('provider unavailable (HTTP 429)', $e->getMessage());
        }
        self::assertCount(2, $this->slept, 'three attempts: two waits');
    }

    #[DataProvider('providers')]
    public function testClientErrorsAreNeverRetried(string $name): void
    {
        foreach ([400, 404] as $status) {
            $this->slept = [];
            $this->timeouts = [];
            $provider = $this->provider($name, [self::httpError($status), self::ok($name)], 3);

            try {
                $this->ask($provider);
                self::fail("HTTP $status must not be retried into success");
            } catch (AnalyzerFailed) {
            }
            self::assertCount(1, $this->timeouts, "HTTP $status: exactly one attempt");
            self::assertSame([], $this->slept);
        }
    }

    #[DataProvider('providers')]
    public function testAnAuthenticationErrorIsNotRetried(string $name): void
    {
        $provider = $this->provider($name, [self::httpError(401), self::ok($name)], 3);

        try {
            $this->ask($provider);
            self::fail('HTTP 401 must fail');
        } catch (AnalyzerFailed $e) {
            self::assertSame('authentication failed', $e->getMessage());
        }
        self::assertSame([], $this->slept, 'no retry for 401');
    }

    #[DataProvider('providers')]
    public function testATimeoutIsRetried(string $name): void
    {
        $this->ask($this->provider($name, [self::timeout(), self::ok($name)], 1));

        self::assertSame([1], $this->slept);
    }

    #[DataProvider('providers')]
    public function testZeroRetriesMeansExactlyOneAttempt(string $name): void
    {
        $provider = $this->provider($name, [self::httpError(503), self::ok($name)], 0);

        try {
            $this->ask($provider);
            self::fail('a 503 with no retries must fail');
        } catch (AnalyzerFailed $e) {
            self::assertSame('provider unavailable (HTTP 503)', $e->getMessage());
        }
        self::assertCount(1, $this->timeouts);
        self::assertSame([], $this->slept);
    }

    #[DataProvider('providers')]
    public function testTheWaitsNeverExceedTheCallTimeout(string $name): void
    {
        $provider = $this->provider($name, [self::httpError(503), self::httpError(503), self::httpError(503), self::httpError(503)], 3);

        try {
            $this->ask($provider);
            self::fail('503 on every attempt must fail');
        } catch (AnalyzerFailed) {
        }
        self::assertLessThanOrEqual(self::TIMEOUT, array_sum($this->slept));
    }

    #[DataProvider('providers')]
    public function testNoRetryStartsAfterTheCallTimeoutIsSpent(string $name): void
    {
        // Each attempt costs 9 of the 10 seconds: the wait before the second attempt would end past the timeout.
        $provider = $this->provider($name, [self::httpError(503), self::ok($name)], 3, cost: 9.0);

        try {
            $this->ask($provider);
            self::fail('no time is left for a second attempt');
        } catch (AnalyzerFailed $e) {
            self::assertSame('provider unavailable (timeout)', $e->getMessage());
        }
        self::assertCount(1, $this->timeouts, 'the second attempt never starts');
        self::assertSame([], $this->slept);
    }

    #[DataProvider('providers')]
    public function testEachAttemptGetsOnlyTheTimeLeftOfTheCall(string $name): void
    {
        $this->ask($this->provider($name, [self::httpError(503), self::httpError(503), self::ok($name)], 2, cost: 2.0));

        // Attempt 1 gets the whole timeout and costs 2 s. Wait 1 s; attempt 2 gets 7 s and costs 2 s. Wait 3 s; attempt 3 gets 2 s.
        self::assertSame([10.0, 7.0, 2.0], $this->timeouts);
    }

    #[DataProvider('providers')]
    public function testAnExhaustedTimeoutNamesTheOriginalError(string $name): void
    {
        $provider = $this->provider($name, [self::timeout(), self::timeout()], 1);

        try {
            $this->ask($provider);
            self::fail('a timeout on every attempt must fail');
        } catch (AnalyzerFailed $e) {
            self::assertSame('provider unavailable (ConnectException)', $e->getMessage());
        }
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function transientStatuses(): array
    {
        $cases = [];
        foreach (['gemini', 'openai'] as $name) {
            foreach ([500, 502, 504] as $status) {
                $cases["$name $status"] = [$name, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('transientStatuses')]
    public function testTransientStatusesAreRetried(string $name, int $status): void
    {
        $this->ask($this->provider($name, [$this->httpError($status), $this->ok($name)], 1));

        self::assertSame([1], $this->slept, "HTTP $status is retried");
    }

    #[DataProvider('providers')]
    public function testA403IsNotRetried(string $name): void
    {
        $provider = $this->provider($name, [$this->httpError(403), $this->ok($name)], 3);

        try {
            $this->ask($provider);
            self::fail('HTTP 403 must fail');
        } catch (AnalyzerFailed $e) {
            self::assertSame('authentication failed', $e->getMessage());
        }
        self::assertSame([], $this->slept);
        self::assertCount(1, $this->timeouts);
    }

    #[DataProvider('providers')]
    public function testAnHttpDateRetryAfterSetsTheWait(string $name): void
    {
        $at = gmdate('D, d M Y H:i:s', time() + 5) . ' GMT'; // the call timeout is 10 s: a wait of 20 would end it
        $this->ask($this->provider($name, [$this->httpError(429, $at), $this->ok($name)], 1));

        self::assertCount(1, $this->slept);
        self::assertGreaterThanOrEqual(4, $this->slept[0]);
        self::assertLessThanOrEqual(5, $this->slept[0]);
    }
}
