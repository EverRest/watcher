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
use Watcher\Tests\Fakes\HostileValues;

final class LokiSourceTest extends LogSourceContractTest
{
    private const SECRET = 'tok-s3cret';
    private const BASE_NS = 1792627200; // 2026-10-22T00:00:00Z
    private const PAGE = 5000;

    /** @var list<array{request: RequestInterface, options: array<string, mixed>}> */
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
        $this->assertStringStartsWith('{app="svc", env="stg"} |~ "(?i)(?:\\\\b(?:warn|warning|err|error|crit|critical|alert|emerg|emergency|fatal)\\\\b|(?:^\\\\s|^#\\\\d{1,4}\\\\b|^at\\\\s|^Caused by:|^Stack trace:|^PHP (?:Stack trace:|\\\\d+\\\\.)|^Traceback|^goroutine \\\\d+ \\\\[|^created by |^exit status \\\\d|^stack backtrace:))|(?:(?i)timeout \\\\d+)"', $q['query']);
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

    public function testRequestsNeverFollowRedirectsAndPinTlsVerification(): void
    {
        $source = $this->makeSourceWithLines([$this->line('ERROR', 'x')]);
        $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 10)));

        $this->assertNotSame([], $this->history);
        foreach ($this->history as $h) {
            $this->assertFalse($h['options']['allow_redirects']);
            $this->assertTrue($h['options']['verify']);
        }
    }

    public function testTheLevelLabelOfTheStreamIsHandedToTheContinuationRule(): void
    {
        $values = [[$this->ns(self::BASE_NS), 'panic: boom'], [$this->ns(self::BASE_NS + 1), 'goroutine 1 [running]:'], [$this->ns(self::BASE_NS + 2), 'something odd']];
        $body = static fn (array $labels): array => ['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [['stream' => $labels, 'values' => $values]]]];

        $labelled = $this->source([$this->json($body(['app' => 'svc', 'level' => 'error']))]);
        $plain = $this->source([$this->json($body(['app' => 'svc']))]);

        $this->assertCount(2, $this->collect($labelled->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 10))), 'a labelled stream starts a record per line');
        $this->assertCount(1, $this->collect($plain->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 10))), 'an unlabelled one lets the odd line continue the record');
    }

    public function testPlainLinesAfterAJsonRecordAreNotSwallowedByIt(): void
    {
        $source = $this->makeSourceWithLines([
            '{"message":"attacker marker","level":"error"}',
            'Payment gateway unreachable, orders failing',
            'Database connection pool exhausted',
        ]);

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 10)));

        $this->assertCount(3, $events);
        $this->assertSame('Payment gateway unreachable, orders failing', $events[1]->message);
    }

    public function testAStreamWithALevelLabelNeverAppendsAnUnrecognisedLine(): void
    {
        $values = [
            [$this->ns(self::BASE_NS), 'FATAL boom {"a":1}'],
            [$this->ns(self::BASE_NS + 1), 'something the app printed'],
            [$this->ns(self::BASE_NS + 2), '    at Foo.bar (x.js:1:1)'],
        ];
        $source = $this->source([$this->json(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
            ['stream' => ['app' => 'svc', 'level' => 'error'], 'values' => $values],
        ]]])]);

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 10)));

        $this->assertCount(2, $events);
        $this->assertSame(Level::Error, $events[1]->level); // the stream label, not a quiet Info
    }

    public function testAnOversizedPlainLineIsDroppedAndCounted(): void
    {
        $source = $this->makeSourceWithLines(['ERROR small', 'ERROR ' . str_repeat('x', ReadFilter::MAX_LINE_BYTES)]);
        $filter = new ReadFilter(Level::Debug, [], 10);

        $this->assertCount(1, $this->collect($source->lines($this->window(), $this->selector(), $filter)));
        $this->assertSame(1, $filter->oversizedLines);
    }

    public function testAnOversizedJsonLineIsNotSwallowedByTheRecordBeforeIt(): void
    {
        $source = $this->makeSourceWithLines([
            '[2026-10-22 10:00:00] production.ERROR: first {}',
            '    at frame()',
            '{"level":"error","message":"second","pad":"' . str_repeat('p', 300000) . '","t":"late.leak@example.org"}',
            str_repeat('x', 20000),
        ]);

        $filter = new ReadFilter(Level::Debug, [], 10);
        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), $filter));

        // The 300 KB structured line reaches the parser and becomes a placeholder with its level (R-4); the 20 KB plain line is
        // under the cap. Neither is swallowed into (or leaked through) the record before it.
        $this->assertSame(0, $filter->oversizedLines);
        $this->assertCount(3, $events);
        $this->assertSame('first', $events[0]->message);
        $this->assertSame(Level::Error, $events[1]->level);
        $this->assertSame('[structured line too large, content withheld]', $events[1]->message);
        $this->assertSame(1, ($source instanceof LokiSource ? $source->readDiagnostics() : [])['truncated_structured'] ?? 0);
        foreach ($events as $e) {
            $this->assertStringNotContainsString('leak', $e->message);
        }
    }

    public function testAFloodOfIndentedLinesIsCutIntoBoundedRecords(): void
    {
        $lines = ['[2026-10-22 10:00:00] production.ERROR: head {}'];
        for ($i = 0; $i < 450; ++$i) {
            $lines[] = '    at frame' . $i;
        }
        $source = $this->makeSourceWithLines($lines);

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 1000)));

        $this->assertGreaterThanOrEqual(3, count($events));
    }

    public function testPlainUnstructuredLinesBecomeOneEventEach(): void
    {
        $source = $this->makeSourceWithLines(\Watcher\Tests\Unit\Source\EventAssemblerTest::fixtureLines('plain_mixed.log'));

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 1000)));

        $this->assertCount(43, $events);
        $this->assertSame(Level::Error, $events[0]->level);
        $this->assertSame(Level::Warning, $events[1]->level);
        $this->assertSame(Level::Emergency, $events[40]->level);
        $this->assertSame(['/var/www/a.php:9'], $events[40]->frames);
    }

    public function testOrphanTraceLinesAtThePageStartNeverBecomeEvents(): void
    {
        $source = $this->makeSourceWithLines(['main.foo(0xc000123, 0x1)', "\t/app/main.go:42 +0x1d", 'goroutine 7 [running]:', 'ERROR real']);

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 100)));

        $this->assertCount(1, $events);
        $this->assertSame('ERROR real', $events[0]->message);
    }

    public function testOrphanCounterIsExposedAndResetPerLinesCall(): void
    {
        $values = [[$this->ns(self::BASE_NS), '#0 /a.php(1): f()'], [$this->ns(self::BASE_NS + 1), "\tat x.y(Z.java:1)"], [$this->ns(self::BASE_NS + 2), 'ERROR real']];
        $source = $this->source([$this->streams($values), $this->streams($values)]);
        $filter = new ReadFilter(Level::Debug, [], 100);

        $counts = [$source->orphanTraceLines()];
        $this->collect($source->lines($this->window(), $this->selector(), $filter));
        $counts[] = $source->orphanTraceLines();
        $this->collect($source->lines($this->window(), $this->selector(), $filter));
        $counts[] = $source->orphanTraceLines();
        $this->assertSame([0, 2, 2], $counts);
    }

    public function testReadDiagnosticsDescribeTheLastLinesCallOnly(): void
    {
        $values = [[$this->ns(self::BASE_NS), '#0 /a.php(1): f()'], [$this->ns(self::BASE_NS + 1), "\tat x.y(Z.java:1)"], [$this->ns(self::BASE_NS + 2), '{"level":"banana","message":"m"}']];
        $source = $this->source([$this->streams($values), $this->streams($values)]);
        $filter = new ReadFilter(Level::Debug, [], 100);

        $this->assertSame(['unknown_levels' => 0, 'orphan_trace_lines' => 0, 'message_tails_dropped' => 0], $source->readDiagnostics());
        $this->collect($source->lines($this->window(), $this->selector(), $filter));
        $this->collect($source->lines($this->window(), $this->selector(), $filter));
        $this->assertSame(['unknown_levels' => 1, 'orphan_trace_lines' => 2, 'message_tails_dropped' => 0], $source->readDiagnostics());
    }

    public function testExceptionHeaderOrphanBecomesAnEvent(): void
    {
        $source = $this->makeSourceWithLines(['App\\FooException: db down', 'ERROR real']);

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 100)));

        $this->assertCount(2, $events);
        $this->assertSame('App\\FooException: db down', $events[0]->message);
    }

    public function testOrphanFrameLineInALevelLabelledStreamBecomesAnEvent(): void
    {
        $body = $this->json(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
            ['stream' => ['app' => 'svc', 'level' => 'error'], 'values' => [[$this->ns(self::BASE_NS), '#0 /a.php(1): f()']]],
        ]]]);
        $source = $this->source([$body]);

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, [], 10)));

        $this->assertCount(1, $events);
        $this->assertSame(Level::Error, $events[0]->level);
        $this->assertSame(0, $source->orphanTraceLines());
    }

    public function testLevelFromStreamLabelWinsForALineWithoutALevelWord(): void
    {
        $body = $this->json(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
            ['stream' => ['app' => 'svc', 'level' => 'error'], 'values' => [[$this->ns(self::BASE_NS), 'Connection refused']]],
        ]]]);
        $source = $this->source([$body]);

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, [], 10)));

        $this->assertCount(1, $events);
        $this->assertSame(Level::Error, $events[0]->level);
        // Unworded main lines still need `level_mode: label` (or `all`) so Loki returns them; text mode only adds
        // stack-trace continuation shapes to the line filter (H5).
    }

    public function testTextModeLineFilterAdmitsStackContinuationLines(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('empty.json'))]);

        $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, [], 10)));

        $query = $this->query($this->history[0]['request'])['query'];
        $this->assertStringContainsString('|~ ', $query);
        $this->assertStringContainsString('stack backtrace:', $query);
        $this->assertStringContainsString('^#', $query);
    }

    public function testTextModeAssemblesStackFramesIntoTheErrorRecord(): void
    {
        $source = $this->makeSourceWithLines([
            'ERROR payment failed',
            '#0 /app/Pay.php(12): charge()',
            "\t at gateway.php:9",
        ]);

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, [], 10)));

        $this->assertCount(1, $events);
        $this->assertSame('ERROR payment failed', $events[0]->message);
        $this->assertContains('/app/Pay.php:12', $events[0]->frames);
    }

    public function testLabelModeUsesAStreamMatcherAndNoLineFilter(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('empty.json'))], self::SECRET, null, null, null, 120, 'label');

        $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 10)));

        $this->assertSame(
            '{app="svc", env="stg", level=~"(?i)(?:warn|warning|err|error|crit|critical|alert|emerg|emergency|fatal|panic)"}',
            $this->query($this->history[0]['request'])['query'],
        );
    }

    public function testLabelModeWithWatchPatternsCannotOrAStreamMatcherSoItReadsUnrestricted(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('empty.json'))], self::SECRET, null, null, null, 120, 'label');

        $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, ['timeout'], 10)));

        $this->assertSame('{app="svc", env="stg"}', $this->query($this->history[0]['request'])['query']);
    }

    public function testAllModeHasNoServerSideLevelRestriction(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('empty.json'))], self::SECRET, null, null, null, 120, 'all');

        $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, ['timeout'], 10)));

        $this->assertSame('{app="svc", env="stg"}', $this->query($this->history[0]['request'])['query']);
    }

    public function testTextModeIsTheDefaultAndTotalsAreUnaffectedByMode(): void
    {
        $source = $this->source([$this->json(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])], self::SECRET, null, null, null, 120, 'label');

        $source->totals($this->window(), $this->selector());

        $this->assertStringStartsWith('sum by (level) (count_over_time({app="svc", env="stg"}[', $this->query($this->history[0]['request'])['query']);
    }

    public function testInvalidLevelModeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->source([], self::SECRET, null, null, null, 120, 'bogus');
    }

    /** @return array<string, array{string}> */
    public static function nonTextModes(): array
    {
        return ['label' => ['label'], 'all' => ['all']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonTextModes')]
    public function testUnwordedLineOfALabelledStreamIsReturned(string $mode): void
    {
        $body = $this->json(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
            ['stream' => ['app' => 'svc', 'level' => 'error'], 'values' => [[$this->ns(self::BASE_NS), 'Connection refused']]],
        ]]]);
        $source = $this->source([$body], self::SECRET, null, null, null, 120, $mode);

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, [], 10)));

        $this->assertCount(1, $events);
        $this->assertSame(Level::Error, $events[0]->level);
        $this->assertStringNotContainsString('|~', $this->query($this->history[0]['request'])['query']);
    }

    public function testAllModeHonoursEventCapAndTruncation(): void
    {
        $values = [];
        for ($i = 0; $i < 3; ++$i) {
            $values[] = [$this->ns(self::BASE_NS + $i), $this->line('ERROR', "e{$i}")];
        }
        $source = $this->source([$this->streams($values)], self::SECRET, null, null, null, 120, 'all');

        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, [], 2)));

        $this->assertCount(2, $events);
        $this->assertTrue($source->lastReadTruncated());
    }

    public function testErrorTypeWatchRuleKeepsAnInfoLineWhoseExceptionClassMatches(): void
    {
        $line = (string) json_encode(['level' => 'info', 'message' => 'charge declined', 'exception.class' => 'App\\PaymentGatewayException']);
        $source = $this->source([$this->streams([[$this->ns(self::BASE_NS), $line]])]);

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, ['PaymentGatewayException'], 10)));

        $this->assertCount(1, $events);
        $this->assertSame('charge declined', $events[0]->message);
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

    public function testMalformedLinesAreSkippedAndCounted(): void
    {
        $source = $this->source([$this->streams([
            [$this->ns(self::BASE_NS), $this->line('ERROR', 'a')],
            ['not-a-timestamp', $this->line('ERROR', 'b')],
            [$this->ns(self::BASE_NS + 2), $this->line('ERROR', 'c')],
            [$this->ns(self::BASE_NS + 3), $this->line('ERROR', 'd')],
            [$this->ns(self::BASE_NS + 4), ['nested']],
        ])]);

        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, [], 100)));

        $this->assertCount(3, $events);
        $this->assertSame(2, ($source->readDiagnostics()['malformed_entries'] ?? 0));
    }

    public function testAReplyMostlyMalformedFailsTheReadAsMalformed(): void
    {
        $source = $this->source([$this->streams([
            [$this->ns(self::BASE_NS), $this->line('ERROR', 'a')],
            ['not-a-timestamp', $this->line('ERROR', 'b')],
            [$this->ns(self::BASE_NS + 2), ['nested']],
        ])]);

        $this->expectException(SourceUnavailable::class);
        $this->expectExceptionMessage('malformed');

        $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, [], 100)));
    }

    public function testAVersionPointWithoutATimestampIsSkippedNotDatedTo1970(): void
    {
        $source = $this->source([$this->json(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
            ['metric' => ['version' => '1.0.0'], 'values' => [[null, '3']]],
            ['metric' => ['version' => '2.0.0'], 'values' => [[self::BASE_NS, '3']]],
        ]]])]);

        $versions = $source->versions($this->window(), $this->selector(), 'version');

        $this->assertSame(['2.0.0'], array_keys($versions));
        $this->assertSame(1, ($source->readDiagnostics()['malformed_entries'] ?? 0));
    }

    public function testAnUnusableTotalIsSkippedAndCounted(): void
    {
        $source = $this->source([$this->json(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => ['level' => 'error'], 'value' => [self::BASE_NS, '99999999999999999999999']],
            ['metric' => ['level' => 'warn'], 'value' => [self::BASE_NS, '4']],
        ]]])]);

        $this->assertSame(['warning' => 4], $source->totals($this->window(), $this->selector()));
        $this->assertSame(1, ($source->readDiagnostics()['malformed_entries'] ?? 0));
    }

    /** @return array<string, array{mixed}> */
    public static function hostileValues(): array
    {
        return HostileValues::all();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileValues')]
    public function testHostileValuesNeverRaiseAWarningOrATypeError(mixed $value): void
    {
        $lines = $this->source([$this->streams([[$this->ns(self::BASE_NS), $this->line('ERROR', 'a')], [$value, $value]])]);
        $this->readQuietly(fn (): array => $this->collect($lines->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, [], 100))));

        $versions = $this->source([$this->json(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [
            ['metric' => ['version' => '1.0.0'], 'values' => [[$value, $value]]],
        ]]])]);
        $this->readQuietly(fn (): array => $versions->versions($this->window(), $this->selector(), 'version'));

        $totals = $this->source([$this->json(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => ['level' => 'error'], 'value' => [self::BASE_NS, $value]],
        ]]])]);
        $this->readQuietly(fn (): array => $totals->totals($this->window(), $this->selector()));

        $this->expectNotToPerformAssertions(); // the reads themselves are the check: a warning or TypeError fails them
    }

    /**
     * Runs one read. A PHP warning fails the test (as an ErrorException); the only refusal allowed is 'malformed'.
     *
     * @param callable(): mixed $read
     */
    private function readQuietly(callable $read): void
    {
        set_error_handler(static function (int $no, string $message): never {
            throw new \ErrorException($message, 0, $no);
        });
        try {
            $read();
        } catch (SourceUnavailable $e) {
            if ($e->getMessage() !== 'malformed') {
                throw $e;
            }
        } finally {
            restore_error_handler();
        }
    }

    // helpers

    /**
     * @param list<Response|ConnectException> $responses
     */
    private function source(array $responses, ?string $bearer = self::SECRET, ?string $user = null, ?string $password = null, ?string $tenant = null, int $retryBudget = 120, string $levelMode = 'text'): LokiSource
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $log = [];
        $this->history = &$log; // Guzzle fills it by reference
        $stack->push(Middleware::history($log));

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
            $levelMode,
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

    /** @param list<array<mixed>> $values */
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
