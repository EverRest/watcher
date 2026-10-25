<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Watcher\Issues\IssueSource;
use Watcher\Issues\SentryIssueSource;
use Watcher\Level;
use Watcher\Source\SourceUnavailable;
use Watcher\Tests\Fakes\FrozenClock;

final class SentryIssueSourceTest extends IssueSourceContract
{
    private const SECRET = 'tok-s3cret';

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        $this->history = [];
    }

    protected function secret(): string
    {
        return self::SECRET;
    }

    protected function makeSourceWithIssues(int $n): IssueSource
    {
        $page = [];
        for ($i = 1; $i <= $n; ++$i) {
            $page[] = json_decode((string) json_encode(['id' => (string) (1000 + $i), 'title' => "Issue {$i}", 'culprit' => 'App::run', 'level' => 'error', 'status' => 'unresolved',
                'firstSeen' => '2026-10-22T10:00:00Z', 'lastSeen' => '2026-10-22T20:00:00Z', 'stats' => ['24h' => [[1792630800, 5]]]]), true);
        }

        return $this->source([new Response(200, [], (string) json_encode($page))]);
    }

    protected function makeSourceFailing(int $httpStatus): IssueSource
    {
        $r = $httpStatus === 0
            ? new ConnectException('cannot connect to https://sentry.test/?t=' . self::SECRET, new Request('GET', 'https://sentry.test/'))
            : new Response($httpStatus, [], $this->fixture('error_401.json'));

        return $this->source(array_fill(0, 3, $r));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/sentry/' . $name);
    }

    /** @param list<Response|\Throwable> $queue */
    private function source(array $queue, int $maxIssues = 50): SentryIssueSource
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new SentryIssueSource(new Client(['handler' => $stack]), 'https://sentry.test/', 'acme', 'orders-api', self::SECRET, $maxIssues, static function (int $s): void {
        }, 120, new FrozenClock(new \DateTimeImmutable('2026-10-23T01:00:00Z')));
    }

    private function link(string $cursor, bool $more): string
    {
        return sprintf('<https://evil.test/x>; rel="next"; results="%s"; cursor="%s"', $more ? 'true' : 'false', $cursor);
    }

    public function testRequestIsAGetOfTheProjectIssueListOnly(): void
    {
        $s = $this->source([new Response(200, [], '[]')]);

        $s->issues($this->window(), 10);

        $req = $this->history[0]['request'];
        self::assertSame('GET', $req->getMethod());
        self::assertSame('/api/0/projects/acme/orders-api/issues/', $req->getUri()->getPath());
        parse_str($req->getUri()->getQuery(), $q);
        self::assertSame('is:unresolved lastSeen:>=2026-10-22T00:00:00Z', $q['query']);
        self::assertSame('10', $q['limit']);
        self::assertSame('24h', $q['groupStatsPeriod']);
        self::assertSame('Bearer ' . self::SECRET, $req->getHeaderLine('Authorization'));
        self::assertSame('', (string) $req->getBody());
    }

    public function testLongWindowUses14dStats(): void
    {
        $s = $this->source([new Response(200, [], '[]')]);
        $w = new \Watcher\Schedule\Window(new \DateTimeImmutable('2026-10-20T00:00:00Z'), new \DateTimeImmutable('2026-10-23T00:00:00Z'));

        $s->issues($w, 10);

        parse_str($this->history[0]['request']->getUri()->getQuery(), $q);
        self::assertSame('14d', $q['groupStatsPeriod']);
    }

    public function testOnlyAllowlistedFieldsAndWindowedCountSurvive(): void
    {
        $s = $this->source([new Response(200, [], $this->fixture('issues_with_pii.json'))]);

        $r = $s->issues($this->window(), 10)[0];

        self::assertSame('2001', $r->id);
        self::assertSame(Level::Error, $r->level);
        self::assertSame(5, $r->countInWindow); // 3 + 2 inside [start,end); 9 before and 4 at end excluded
        self::assertFalse($r->isRegression);
        self::assertSame(
            ['id', 'title', 'culprit', 'level', 'firstSeen', 'lastSeen', 'countInWindow', 'isRegression'],
            array_keys(get_object_vars($r)),
        );
        self::assertSame('UTC', $r->firstSeen->getTimezone()->getName());
    }

    public function testPagingFollowsCursorNotTheLinkUrlAndStopsAtMaxIssues(): void
    {
        $s = $this->source([
            new Response(200, ['Link' => $this->link('0:100:0', true)], $this->fixture('issues_page.json')),
            new Response(200, ['Link' => $this->link('0:200:0', false)], $this->fixture('issues_page2.json')),
        ]);

        $records = $s->issues($this->window(), 50);

        self::assertSame(['1001', '1002', '1003'], array_map(static fn ($r): string => $r->id, $records));
        self::assertTrue($records[1]->isRegression);
        self::assertSame(Level::Critical, $records[2]->level);
        self::assertCount(2, $this->history);
        self::assertSame('sentry.test', $this->history[1]['request']->getUri()->getHost());
        parse_str($this->history[1]['request']->getUri()->getQuery(), $q);
        self::assertSame('0:100:0', $q['cursor']);
        self::assertSame(3, $s->dropped()); // resolved, no stats, non-numeric id
        self::assertSame(2, $s->requests());
    }

    public function testMaxIssuesStopsPaging(): void
    {
        $s = $this->source([new Response(200, ['Link' => $this->link('0:100:0', true)], $this->fixture('issues_page.json'))]);

        self::assertCount(2, $s->issues($this->window(), 2));
        self::assertCount(1, $this->history);
    }

    public function testMalformedValuesDropTheIssueNeverGuess(): void
    {
        $base = ['id' => '1', 'title' => 'T', 'culprit' => 'c', 'level' => 'error', 'status' => 'unresolved', 'firstSeen' => '2026-10-22T10:00:00Z', 'lastSeen' => '2026-10-22T20:00:00Z', 'stats' => ['24h' => [[1792630800, 1]]]];
        $bad = [
            ['title' => ['x']], ['level' => 'verbose'], ['firstSeen' => 'yesterday'],
            ['stats' => 'oops'], ['stats' => ['24h' => [['a', 'b']]]], ['id' => '12x'], ['status' => 'ignored'],
        ];
        $page = [$base];
        foreach ($bad as $b) {
            $page[] = $b + $base;
        }
        $s = $this->source([new Response(200, [], (string) json_encode($page, 0))]);

        $records = $s->issues($this->window(), 50);

        self::assertCount(1, $records);
        self::assertSame(count($bad), $s->dropped());
    }

    public function testRawTitleAndCulpritAreBoundedButNotCutToDisplaySize(): void
    {
        $item = ['id' => '1', 'title' => str_repeat('a', 400), 'culprit' => str_repeat('b', 200), 'level' => 'info', 'status' => 'unresolved', 'firstSeen' => '2026-10-22T10:00:00Z', 'lastSeen' => '2026-10-22T20:00:00Z', 'stats' => ['24h' => []]];

        $r = $this->source([new Response(200, [], (string) json_encode([$item]))])->issues($this->window(), 5)[0];

        self::assertSame(400, mb_strlen($r->title)); // the display cut is IssueGroupBuilder's, after masking (F6)
        self::assertSame(200, mb_strlen($r->culprit));
        self::assertSame(0, $r->countInWindow);
    }

    public function testStatusesMapToTokens(): void
    {
        $cases = [[new Response(200, [], $this->fixture('bad_json.json')), 'bad_response'], [new Response(200, [], '{"detail":"x"}'), 'bad_response'], [new Response(429, [], ''), 'rate_limited'], [new Response(401, [], ''), 'unauthorized']];
        foreach ($cases as [$response, $token]) {
            try {
                $this->source(array_fill(0, 3, $response))->issues($this->window(), 5);
                self::fail('expected SourceUnavailable');
            } catch (SourceUnavailable $e) {
                self::assertSame($token, $e->getMessage());
            }
        }
    }

    /** @return string a one-issue page whose stats shape is wrong, so the issue is dropped */
    private function droppedPage(): string
    {
        return (string) json_encode([['id' => '1', 'title' => 'T', 'culprit' => 'c', 'level' => 'error', 'status' => 'unresolved',
            'firstSeen' => '2026-10-22T10:00:00Z', 'lastSeen' => '2026-10-22T20:00:00Z', 'stats' => 'oops']]);
    }

    public function testSameCursorAndEmptyPagesStopPaging(): void
    {
        $s = $this->source(array_fill(0, 20, new Response(200, ['Link' => $this->link('0:100:0', true)], '[]')));

        self::assertSame([], $s->issues($this->window(), 50));
        self::assertSame(1, $s->requests());

        $this->history = [];
        $same = $this->source(array_fill(0, 20, new Response(200, ['Link' => $this->link('0:100:0', true)], $this->fixture('issues_page.json'))));
        $same->issues($this->window(), 50);
        self::assertSame(2, $same->requests()); // first page -> cursor 0:100:0, second page repeats it
    }

    public function testRequestsAreCappedWhenEveryItemIsDropped(): void
    {
        $queue = [];
        for ($i = 1; $i <= 10; ++$i) {
            $queue[] = new Response(200, ['Link' => $this->link("0:{$i}00:0", true)], $this->droppedPage());
        }
        $s = $this->source($queue, 50);

        self::assertSame([], $s->issues($this->window(), 50));
        self::assertSame(3, $s->requests()); // ceil(50/100) + 2
        self::assertCount(3, $this->history);
        self::assertSame(3, $s->dropped());
    }

    public function testASubHourWindowCountsTheOverlappingHourlyBucket(): void
    {
        $t = (new \DateTimeImmutable('2026-10-23T10:00:00Z'))->getTimestamp();
        $item = ['id' => '1', 'title' => 'T', 'culprit' => 'c', 'level' => 'error', 'status' => 'unresolved', 'firstSeen' => '2026-10-23T10:08:00Z', 'lastSeen' => '2026-10-23T10:20:00Z', 'stats' => ['24h' => [[$t - 3600, 7], [$t, 50], [$t + 3600, 9]]]];
        $w = new \Watcher\Schedule\Window(new \DateTimeImmutable('2026-10-23T10:07:00Z'), new \DateTimeImmutable('2026-10-23T10:22:00Z'));

        $r = $this->source([new Response(200, [], (string) json_encode([$item]))])->issues($w, 5);

        self::assertSame(50, $r[0]->countInWindow);
    }

    public function testADailyBucketCountsWhenItOverlapsTheWindow(): void
    {
        $d = (new \DateTimeImmutable('2026-10-20T00:00:00Z'))->getTimestamp();
        $item = ['id' => '1', 'title' => 'T', 'culprit' => 'c', 'level' => 'error', 'status' => 'unresolved', 'firstSeen' => '2026-10-22T10:00:00Z', 'lastSeen' => '2026-10-22T20:00:00Z',
            'stats' => ['14d' => [[$d - 86400, 100], [$d, 7], [$d + 86400, 3]]]];
        $w = new \Watcher\Schedule\Window(new \DateTimeImmutable('2026-10-20T12:00:00Z'), new \DateTimeImmutable('2026-10-22T00:00:00Z'));

        $r = $this->source([new Response(200, [], (string) json_encode([$item]))])->issues($w, 5);

        self::assertSame(10, $r[0]->countInWindow); // 10-20 (overlaps 12:00) + 10-21; 10-19 ends before start
    }

    public function testAWindowOlderThanTheStatsPeriodIsRefused(): void
    {
        $old = new \Watcher\Schedule\Window(new \DateTimeImmutable('2026-10-20T00:00:00Z'), new \DateTimeImmutable('2026-10-21T00:00:00Z'));
        try {
            $this->source([new Response(200, [], '[]')])->issues($old, 5);
            self::fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            self::assertSame('window_too_old', $e->getMessage());
        }
        self::assertCount(0, $this->history);
    }

    public function testNetworkExceptionsMapToTimeoutOrUnreachable(): void
    {
        $req = new Request('GET', 'https://sentry.test/');
        foreach ([['cURL error 28: Operation timed out', 'timeout'], ['Could not resolve host sentry.test', 'unreachable']] as [$msg, $token]) {
            try {
                $this->source(array_fill(0, 3, new ConnectException($msg, $req)))->issues($this->window(), 5);
                self::fail('expected SourceUnavailable');
            } catch (SourceUnavailable $e) {
                self::assertSame($token, $e->getMessage());
            }
        }
    }
}
