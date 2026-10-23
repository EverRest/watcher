<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Watcher\Level;
use Watcher\Source\LogEvent;
use Watcher\Source\LogSource;
use Watcher\Source\LokiSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;

final class LokiSourceTest extends LogSourceContractTest
{
    private const SECRET = 'tok-s3cret';
    private const BASE_NS = 1792627200; // 2026-10-22T00:00:00Z
    private const PAGE = 5000;

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->slept = [];
        $this->history = [];
    }

    protected function secret(): string
    {
        return self::SECRET;
    }

    protected function makeSourceWithTotals(array $totals): LogSource
    {
        $result = [];
        foreach ($totals as $level => $n) {
            $result[] = ['metric' => ['level' => $level], 'value' => [self::BASE_NS, (string) $n]];
        }

        return $this->source([$this->json(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $result]])]);
    }

    protected function makeSourceWithLines(array $rawLines): LogSource
    {
        $values = [];
        foreach ($rawLines as $i => $line) {
            $values[] = [$this->ns(self::BASE_NS + $i), $line];
        }

        return $this->source([$this->streams($values)]);
    }

    protected function makeSourceWithManyLines(int $n): LogSource
    {
        $values = [];
        for ($i = 0; $i < $n; ++$i) {
            $values[] = [$this->ns(self::BASE_NS + $i), $this->line('ERROR', "e{$i}")];
        }
        // Loki's `start` is inclusive: page 2 begins with the last entry of page 1.
        $pages = [array_slice($values, 0, self::PAGE), array_slice($values, self::PAGE - 1)];

        return $this->source(array_map(fn (array $v): Response => $this->streams($v), $pages));
    }

    protected function makeSourceWithVersions(array $versions): LogSource
    {
        $result = [];
        foreach ($versions as $v => $first) {
            $result[] = ['metric' => ['version' => $v], 'values' => [[$first->getTimestamp() - 3600, '0'], [$first->getTimestamp(), '3']]];
        }

        return $this->source([$this->json(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => $result]])]);
    }

    protected function makeSourceFailing(int $httpStatus): LogSource
    {
        $response = $httpStatus === 0
            ? new ConnectException('cannot connect to https://loki.test/?token=' . self::SECRET, new Request('GET', 'https://loki.test/'))
            : new Response($httpStatus, [], $this->fixture('error_unauthorized.json'));

        return $this->source(array_fill(0, 3, $response));
    }

    public function testTotalsRequestAndFixtureMapping(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('totals_vector.json'))]);

        $totals = $source->totals($this->window(), $this->selector());

        // warn + warning merge; a series without a level label counts as info
        $this->assertSame(['error' => 12, 'warning' => 35, 'info' => 907], $totals);
        $req = $this->history[0]['request'];
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('/loki/api/v1/query', $req->getUri()->getPath());
        $q = $this->query($req);
        $this->assertSame('sum by (level) (count_over_time({app="svc", env="stg"}[86400s]))', $q['query']);
        $this->assertSame($this->ns(self::BASE_NS + 86400), $q['time']);
        $this->assertSame('Bearer ' . self::SECRET, $req->getHeaderLine('Authorization'));
        $this->assertFalse($req->hasHeader('X-Scope-OrgID'));
    }

    public function testBasicAuthAndTenantHeader(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('totals_vector.json'))], bearer: null, user: 'u', password: 'p', tenant: 'team-a');

        $source->totals($this->window(), $this->selector());

        $req = $this->history[0]['request'];
        $this->assertSame('Basic ' . base64_encode('u:p'), $req->getHeaderLine('Authorization'));
        $this->assertSame('team-a', $req->getHeaderLine('X-Scope-OrgID'));
    }

    public function testLinesRequestHasServerSideFilterLimitAndForwardDirection(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('empty.json'))]);

        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, ['(?i)timeout \d+'], 10)));

        $this->assertSame([], $events);
        $this->assertFalse($source->lastReadTruncated());
        $req = $this->history[0]['request'];
        $this->assertSame('/loki/api/v1/query_range', $req->getUri()->getPath());
        $q = $this->query($req);
        $this->assertStringStartsWith('{app="svc", env="stg"} |~ "(?i)(?:\\\\b(?:warn|warning|err|error|crit|critical|alert|emerg|emergency|fatal)\\\\b)|(?:(?i)timeout \\\\d+)"', $q['query']);
        $this->assertSame('5000', $q['limit']);
        $this->assertSame('forward', $q['direction']);
        $this->assertSame($this->ns(self::BASE_NS), $q['start']);
        $this->assertSame($this->ns(self::BASE_NS + 86400), $q['end']);
    }

    public function testWatchPatternWithEscapedPunctuationIsQuotedForLogQl(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('empty.json'))]);

        $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, ['a\\.b "q"'], 10)));

        $this->assertStringContainsString('|(?:a\\\\.b \\"q\\")"', $this->query($this->history[0]['request'])['query']);
    }

    public function testWatchPatternAlsoReadsAnInfoLineThatMatchesItAndDropsOthers(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('empty.json'))]);
        $filter = new ReadFilter(Level::Error, ['(?i)timeout'], 10);

        $this->assertTrue($filter->matchesWatch('Upstream TIMEOUT after 3s'));
        $this->assertFalse($filter->matchesWatch('all good'));
        $this->assertSame([], $this->collect($source->lines($this->window(), $this->selector(), $filter)));
    }

    public function testNoLineFilterWhenEverythingIsWanted(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('empty.json'))]);

        $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 10)));

        $this->assertSame('{app="svc", env="stg"}', $this->query($this->history[0]['request'])['query']);
    }

    public function testNothingIsRequestedUntilTheGeneratorIsConsumed(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('empty.json'))]);

        $source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 10));

        $this->assertSame([], $this->history);
    }

    public function testFixturePageIsParsedWithStreamLabelsInTimeOrder(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('streams_page.json'))]);

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 10)));

        $this->assertSame(['Payment failed', 'DB down'], array_map(static fn (LogEvent $e): string => $e->message, $events));
        $this->assertSame(Level::Critical, $events[1]->level);
        $this->assertSame('PDOException', $events[1]->errorType);
        $this->assertSame(['app' => 'orders-api', 'env' => 'stg', 'pod' => 'a'], $events[0]->labels);
        $this->assertSame('b', $events[1]->labels['pod']);
    }

    public function testPagingAdvancesStartToLastTimestampAndDedupesTheBoundary(): void
    {
        $source = $this->makeSourceWithManyLines(5100);

        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 100000)));

        $this->assertCount(5100, $events);
        $this->assertFalse($source->lastReadTruncated());
        $this->assertCount(2, $this->history);
        $this->assertSame($this->ns(self::BASE_NS + self::PAGE - 1), $this->query($this->history[1]['request'])['start']);
    }

    public function testLabelValuesAreEscapedInLogQl(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('totals_vector.json'))]);

        $source->totals($this->window(), new StreamSelector(['app' => "a\"b\\c\nd"]));

        $this->assertStringContainsString('{app="a\"b\\\\c\nd"}', $this->query($this->history[0]['request'])['query']);
    }

    public function testInvalidLabelNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->source([])->totals($this->window(), new StreamSelector(['app} or {x' => 'v']));
    }

    public function testMultiLineRecordsArriveAsSeparateEntriesAndAreAssembled(): void
    {
        $lines = [];
        foreach (['laravel_multiline.log', 'query_exception_a.log', 'message_form.log'] as $f) {
            $lines = array_merge($lines, \Watcher\Tests\Unit\Source\EventAssemblerTest::fixtureLines($f));
        }
        $source = $this->makeSourceWithLines($lines);

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, [], 10)));

        $this->assertSame(
            ['App\Exceptions\QuotaException', 'Illuminate\Database\QueryException', 'RuntimeException'],
            array_map(static fn (LogEvent $e): ?string => $e->errorType, $events),
        );
        $this->assertSame('/var/www/html/app/Services/Quota.php:42', $events[0]->frames[0]);
        $this->assertFalse($source->lastReadTruncated());
    }

    public function testEventCapCountsAssembledEvents(): void
    {
        $source = $this->makeSourceWithLines(\Watcher\Tests\Unit\Source\EventAssemblerTest::fixtureLines('laravel_multiline.log'));
        // 7 physical lines, 2 records (1 ERROR + 1 INFO); cap 1 of the wanted ones
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 1)));

        $this->assertCount(1, $events);
        $this->assertTrue($source->lastReadTruncated());

        $source = $this->makeSourceWithLines(\Watcher\Tests\Unit\Source\EventAssemblerTest::fixtureLines('laravel_multiline.log'));
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 2)));

        $this->assertCount(2, $events);
        $this->assertFalse($source->lastReadTruncated());
    }

    public function testVersionsRequestAndFixtureMapping(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('versions_matrix.json'))]);

        $got = $source->versions($this->window(), $this->selector(), 'version');

        // zero-count samples are skipped; a series without the label is ignored
        $this->assertSame(['1.4.0', '1.5.0'], array_keys($got));
        $this->assertEquals(new DateTimeImmutable('@1792630800'), $got['1.4.0']);
        $this->assertEquals(new DateTimeImmutable('@1792634400'), $got['1.5.0']);
        $req = $this->history[0]['request'];
        $this->assertSame('/loki/api/v1/query_range', $req->getUri()->getPath());
        $q = $this->query($req);
        $this->assertSame('count by (version) (count_over_time({app="svc", env="stg"}[1h]))', $q['query']);
        $this->assertSame('3600', $q['step']);
    }

    public function testVersionsEmptyFixture(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('versions_empty.json'))]);

        $this->assertSame([], $source->versions($this->window(), $this->selector(), 'version'));
    }

    public function testErrorMessageNamesStatusOnly(): void
    {
        $source = $this->source([new Response(401, [], $this->fixture('error_unauthorized.json'))]);

        try {
            $source->totals($this->window(), $this->selector());
            $this->fail('SourceUnavailable expected');
        } catch (SourceUnavailable $e) {
            $this->assertSame('loki 401 Unauthorized', $e->getMessage());
        }
    }

    public function testTransientStatusIsRetriedWithBackoff(): void
    {
        $source = $this->source([new Response(503), new Response(200, [], $this->fixture('empty.json'))]);

        $source->totals($this->window(), $this->selector());

        $this->assertCount(2, $this->history);
        $this->assertSame([1], $this->slept);
    }

    public function testGivesUpAfterThreeAttemptsWithoutLeakingTheToken(): void
    {
        $source = $this->source(array_fill(0, 5, new Response(503, [], 'tok=' . self::SECRET)));

        try {
            $source->totals($this->window(), $this->selector());
            $this->fail('SourceUnavailable expected');
        } catch (SourceUnavailable $e) {
            $this->assertSame('loki 503 Service Unavailable', $e->getMessage());
        }
        $this->assertCount(3, $this->history);
        $this->assertSame([1, 3], $this->slept);
    }

    public function testServerErrorIsRetriedButUnauthorizedIsNot(): void
    {
        $this->source([new Response(500), new Response(200, [], $this->fixture('empty.json'))])->totals($this->window(), $this->selector());
        $this->assertCount(2, $this->history);

        $this->history = [];
        try {
            $this->source([new Response(401), new Response(200, [], $this->fixture('empty.json'))])->totals($this->window(), $this->selector());
            $this->fail('SourceUnavailable expected');
        } catch (SourceUnavailable) {
            $this->assertCount(1, $this->history);
        }
    }

    public function testConnectExceptionIsRetried(): void
    {
        $source = $this->source([
            new ConnectException('boom ' . self::SECRET, new Request('GET', 'https://loki.test/')),
            new Response(200, [], $this->fixture('empty.json')),
        ]);

        $source->totals($this->window(), $this->selector());

        $this->assertSame([1], $this->slept);
    }

    public function testRateLimitHonoursRetryAfterCapped(): void
    {
        $source = $this->source([
            new Response(429, ['Retry-After' => '7']),
            new Response(429, ['Retry-After' => '900']),
            new Response(200, [], $this->fixture('empty.json')),
        ]);

        $source->totals($this->window(), $this->selector());

        $this->assertSame([7, 30], $this->slept);
    }

    public function testRetryWaitBudgetStopsRetrying(): void
    {
        $source = $this->source(array_fill(0, 5, new Response(503)), self::SECRET, null, null, null, 3);

        try {
            $source->totals($this->window(), $this->selector());
            $this->fail('SourceUnavailable expected');
        } catch (SourceUnavailable) {
            $this->assertCount(2, $this->history);
        }
        $this->assertSame([1], $this->slept);
    }

    public function testMoreThanAPageOfEntriesAtOneNanosecondIsReportedTruncated(): void
    {
        $values = [];
        for ($i = 0; $i < self::PAGE; ++$i) {
            $values[] = [$this->ns(self::BASE_NS), $this->line('ERROR', "same{$i}")];
        }
        $source = $this->source([$this->streams($values), $this->streams($values)]);

        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 100000)));

        $this->assertCount(self::PAGE, $events);
        $this->assertTrue($source->lastReadTruncated());
        $this->assertCount(1, $this->history);
    }

    public function testUnevaluablePatternFailsOpenAndIsCounted(): void
    {
        $filter = new ReadFilter(Level::Error, ['(unclosed'], 10);

        $this->assertSame(0, $filter->patternErrors);
        $this->assertTrue($filter->matchesWatch('anything'));
        $this->assertSame(1, $filter->patternErrors);

        $ok = new ReadFilter(Level::Error, ['slow'], 10);
        $this->assertFalse($ok->matchesWatch('fast'));
        $this->assertSame(0, $ok->patternErrors);
    }

    // helpers

    /**
     * @param list<Response|ConnectException> $responses
     */
    private function source(array $responses, ?string $bearer = self::SECRET, ?string $user = null, ?string $password = null, ?string $tenant = null, int $retryBudget = 120): LokiSource
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new LokiSource(
            new Client(['handler' => $stack]),
            'https://loki.test/',
            $bearer,
            $user,
            $password,
            $tenant,
            function (int $s): void {
                $this->slept[] = $s;
            },
            $retryBudget,
        );
    }

    /** @return array<string, string> */
    private function query(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $q);

        $out = [];
        foreach ($q as $k => $v) {
            $out[(string) $k] = is_string($v) ? $v : '';
        }

        return $out;
    }

    /** @param list<array{string, string}> $values */
    private function streams(array $values): Response
    {
        return $this->json(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
            ['stream' => ['app' => 'svc'], 'values' => $values],
        ]]]);
    }

    /** @param array<mixed> $body */
    private function json(array $body): Response
    {
        return new Response(200, [], (string) json_encode($body));
    }

    private function ns(int $seconds): string
    {
        return $seconds . '000000000';
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/loki/' . $name);
    }
}
