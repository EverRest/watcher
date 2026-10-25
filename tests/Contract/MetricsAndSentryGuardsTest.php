<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Issues\SentryIssueSource;
use Watcher\Metrics\PrometheusSource;
use Watcher\Schedule\Window;
use Watcher\Source\ResponseLimit;
use Watcher\Source\SourceUnavailable;
use Watcher\Tests\Fakes\FrozenClock;

/** The HTTP envelope both sources share (review F1, F3, F4, F5): behaviour against a fake handler, not source greps. */
final class MetricsAndSentryGuardsTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    /** @param list<Response|\Throwable> $queue */
    private function client(array $queue): Client
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    private function nap(): \Closure
    {
        return static function (int $s): void {
        };
    }

    /**
     * @param list<Response|\Throwable> $queue
     * @param array<string, mixed>      $extra
     */
    private function prom(array $queue, array $extra = []): PrometheusSource
    {
        return new PrometheusSource($this->client($queue), $extra['url'] ?? 'https://prom.test/', 't', $extra['uid'] ?? null, [], 500, $this->nap(), 120, false, $extra['budget'] ?? 60, $extra['stop'] ?? null, $extra['now'] ?? null);
    }

    /**
     * @param list<Response|\Throwable> $queue
     * @param array<string, mixed>      $extra
     */
    private function sentry(array $queue, array $extra = []): SentryIssueSource
    {
        return new SentryIssueSource(
            $this->client($queue),
            $extra['url'] ?? 'https://sentry.test/',
            $extra['org'] ?? 'acme',
            $extra['project'] ?? 'api',
            't',
            50,
            $this->nap(),
            120,
            new FrozenClock(new DateTimeImmutable('2026-10-23T01:00:00Z')),
            false,
            $extra['budget'] ?? 60,
            $extra['stop'] ?? null,
            $extra['now'] ?? null
        );
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-23T00:00:00Z');
    }

    private function window(): Window
    {
        return Window::endingAt($this->at());
    }

    private function failure(callable $f): string
    {
        try {
            $f();
        } catch (SourceUnavailable $e) {
            return $e->getMessage();
        }
        self::fail('expected SourceUnavailable');
    }

    private function page(int $i): string
    {
        return (string) json_encode([['id' => (string) $i, 'title' => 'T', 'culprit' => 'c', 'level' => 'error', 'status' => 'unresolved',
            'firstSeen' => '2026-10-22T10:00:00Z', 'lastSeen' => '2026-10-22T20:00:00Z', 'stats' => ['24h' => [[1792630800, 5]]]]]);
    }

    public function testARedirectIsNeverFollowed(): void
    {
        $redirect = new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']);
        $ok = new Response(200, [], (string) json_encode(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]));

        self::assertSame('bad_response', $this->failure(fn () => $this->prom([$redirect, $ok])->instant('up', $this->at())));
        self::assertCount(1, $this->history);
        self::assertFalse($this->history[0]['options']['allow_redirects']);
        self::assertTrue($this->history[0]['options']['verify']);

        self::assertSame('bad_response', $this->failure(fn () => $this->sentry([$redirect, new Response(200, [], '[]')])->issues($this->window(), 5)));
        self::assertCount(1, $this->history);
        self::assertFalse($this->history[0]['options']['allow_redirects']);
        self::assertTrue($this->history[0]['options']['verify']);
    }

    public function testAnOversizedBodyIsASourceFailureNotAMemoryError(): void
    {
        $big = str_repeat('a', ResponseLimit::MAX_BYTES + 1);
        $declared = new Response(200, ['Content-Length' => (string) (ResponseLimit::MAX_BYTES + 1)], '{}');

        self::assertSame('too_large', $this->failure(fn () => $this->prom([new Response(200, [], $big)])->instant('up', $this->at())));
        self::assertSame('too_large', $this->failure(fn () => $this->prom(array_fill(0, 3, $declared))->instant('up', $this->at())));
        self::assertSame('too_large', $this->failure(fn () => $this->sentry([new Response(200, [], $big)])->issues($this->window(), 5)));
        self::assertSame('too_large', $this->failure(fn () => $this->sentry(array_fill(0, 3, $declared))->issues($this->window(), 5)));
    }

    public function testJsonDeeperThanTheLimitIsBadResponse(): void
    {
        $deep = str_repeat('[', 100) . str_repeat(']', 100);

        self::assertSame('bad_response', $this->failure(fn () => $this->prom([new Response(200, [], $deep)])->instant('up', $this->at())));
        self::assertSame('bad_response', $this->failure(fn () => $this->sentry([new Response(200, [], $deep)])->issues($this->window(), 5)));
    }

    public function testTheStopHookEndsPagingAndQueriesWithATimeoutToken(): void
    {
        $calls = 0;
        $stop = static function () use (&$calls): ?string {
            return ++$calls > 1 ? 'deadline' : null;
        };
        $next = ['Link' => '<https://x/>; rel="next"; results="true"; cursor="1:0:0"'];
        $s = $this->sentry([new Response(200, $next, $this->page(1)), new Response(200, [], $this->page(2))], ['stop' => $stop]);

        self::assertSame('timeout', $this->failure(fn () => $s->issues($this->window(), 150)));
        self::assertCount(1, $this->history); // the second page was never requested

        $ok = (string) json_encode(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]]);
        $calls = 0;
        $p = $this->prom([new Response(200, [], $ok), new Response(200, [], $ok)], ['stop' => $stop]);
        $p->instant('up', $this->at());
        self::assertSame('timeout', $this->failure(fn () => $p->instant('up', $this->at())));
        self::assertCount(1, $this->history);
    }

    public function testTheWallTimeBudgetStopsRetriesOfOneCall(): void
    {
        $t = 0.0;
        $now = static function () use (&$t): float {
            return $t;
        };
        $slow = new \GuzzleHttp\Exception\ConnectException('boom', new \GuzzleHttp\Psr7\Request('GET', 'https://prom.test/'));
        $p = $this->prom([$slow, $slow, $slow], ['budget' => 60, 'now' => $now, 'stop' => function () use (&$t): ?string {
            $t += 40.0; // each attempt "takes" 40 s

            return null;
        }]);

        self::assertSame('timeout', $this->failure(fn () => $p->instant('up', $this->at())));
        self::assertLessThanOrEqual(2, count($this->history));
    }

    /** @return iterable<string, array{string}> */
    public static function badUrls(): iterable
    {
        foreach (['http://prom.test', 'https://user:pw@prom.test', 'https://169.254.169.254', 'https://0xa9fea9fe', 'https://[fe80::1]', 'https://127.0.0.1', 'https://localhost.', 'ftp://prom.test', 'prom.test', 'https://'] as $u) {
            yield $u => [$u];
        }
    }

    #[DataProvider('badUrls')]
    public function testBaseUrlsAreValidatedByTheSharedRule(string $url): void
    {
        $this->expectException(InvalidConfig::class);
        $this->prom([], ['url' => $url]);
    }

    #[DataProvider('badUrls')]
    public function testSentryBaseUrlsAreValidatedByTheSharedRule(string $url): void
    {
        $this->expectException(InvalidConfig::class);
        $this->sentry([], ['url' => $url]);
    }

    public function testLoopbackNeedsAllowInsecureAndHttpIsAccepted(): void
    {
        new PrometheusSource($this->client([]), 'http://127.0.0.1:9090', null, null, [], 500, null, 120, true);
        $this->expectException(InvalidConfig::class);
        new PrometheusSource($this->client([]), 'http://127.0.0.1:9090');
    }

    /** @return iterable<string, array{string}> */
    public static function badSlugs(): iterable
    {
        foreach (['.', '..', '', '...', 'a/b', 'a b', "a\n", 'a%2f', '../x'] as $s) {
            yield var_export($s, true) => [$s];
        }
    }

    #[DataProvider('badSlugs')]
    public function testSlugsCannotClimbOutOfTheirSegment(string $slug): void
    {
        foreach ([fn () => $this->prom([], ['uid' => $slug]), fn () => $this->sentry([], ['org' => $slug]), fn () => $this->sentry([], ['project' => $slug])] as $make) {
            try {
                $make();
                self::fail('accepted ' . var_export($slug, true));
            } catch (InvalidConfig) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAWindowThatStartsBeforeTheStatsRetentionIsRefused(): void
    {
        // now = 2026-10-23T01:00Z; 24h stats: a window of [now-47h, now-23h] ends inside retention but starts before it
        $w = new Window(new DateTimeImmutable('2026-10-21T02:00:00Z'), new DateTimeImmutable('2026-10-22T02:00:00Z'));
        self::assertSame('window_too_old', $this->failure(fn () => $this->sentry([new Response(200, [], '[]')])->issues($w, 5)));
        self::assertCount(0, $this->history);

        // a window starting within one bucket of the horizon is still counted (that bucket overlaps it)
        $edge = new Window(new DateTimeImmutable('2026-10-22T00:00:00Z'), new DateTimeImmutable('2026-10-23T00:00:00Z'));
        self::assertSame([], $this->sentry([new Response(200, [], '[]')])->issues($edge, 5));
    }

    public function testATrailingNewlineIdIsDropped(): void
    {
        $item = json_decode($this->page(1), true)[0];
        $item['id'] = "123\n";
        $s = $this->sentry([new Response(200, [], (string) json_encode([$item]))]);

        self::assertSame([], $s->issues($this->window(), 5));
        self::assertSame(1, $s->dropped());
    }
}
