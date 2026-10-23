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
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Watcher\Level;
use Watcher\Source\DatadogSource;
use Watcher\Source\LogEvent;
use Watcher\Source\LogSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;

final class DatadogSourceTest extends LogSourceContractTest
{
    private const API_KEY = 'dd-api-s3cret';
    private const APP_KEY = 'dd-app-s3cret';
    private const PAGE = 1000;

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];
    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->history = [];
        $this->slept = [];
    }

    protected function secret(): string
    {
        return self::API_KEY;
    }

    protected function makeSourceWithTotals(array $totals): LogSource
    {
        $buckets = [];
        foreach ($totals as $level => $n) {
            $buckets[] = ['by' => ['status' => $level], 'computes' => ['c0' => $n]];
        }

        return $this->source([$this->json(['meta' => ['status' => 'done'], 'data' => ['buckets' => $buckets]])]);
    }

    protected function makeSourceWithLines(array $rawLines): LogSource
    {
        return $this->source([$this->page($rawLines, null)]);
    }

    protected function makeSourceWithManyLines(int $n): LogSource
    {
        $lines = [];
        for ($i = 0; $i < $n; ++$i) {
            $lines[] = $this->line('ERROR', "e{$i}");
        }
        $chunks = array_chunk($lines, self::PAGE);
        $pages = [];
        foreach ($chunks as $i => $chunk) {
            $pages[] = $this->page($chunk, isset($chunks[$i + 1]) ? 'c' . ($i + 1) : null);
        }

        return $this->source($pages);
    }

    protected function makeSourceWithVersions(array $versions): LogSource
    {
        $buckets = [];
        foreach ($versions as $v => $first) {
            $buckets[] = ['by' => ['version' => $v], 'computes' => ['c0' => [
                ['time' => gmdate('Y-m-d\TH:i:s.000\Z', $first->getTimestamp() - 7200), 'value' => 0],
                ['time' => gmdate('Y-m-d\TH:i:s.000\Z', $first->getTimestamp() - 3600), 'value' => 3],
            ]]];
        }

        return $this->source([$this->json(['meta' => ['status' => 'done'], 'data' => ['buckets' => $buckets]])]);
    }

    protected function makeSourceFailing(int $httpStatus): LogSource
    {
        $response = $httpStatus === 0
            ? new ConnectException('cannot connect to https://api.datadoghq.com/?k=' . self::API_KEY, new Request('POST', 'https://api.datadoghq.com/'))
            : new Response($httpStatus, [], $this->fixture('error_forbidden.json'));

        return $this->source([$response]);
    }

    public function testTotalsRequestAndFixtureMapping(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('totals.json'))]);

        $totals = $source->totals($this->window(), $this->selector());

        $this->assertSame(['error' => 12, 'warning' => 35, 'info' => 907], $totals);
        $req = $this->history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('https://api.datadoghq.eu/api/v2/logs/analytics/aggregate', (string) $req->getUri());
        $this->assertSame(self::API_KEY, $req->getHeaderLine('DD-API-KEY'));
        $this->assertSame(self::APP_KEY, $req->getHeaderLine('DD-APPLICATION-KEY'));
        $this->assertSame([
            'compute' => [['aggregation' => 'count', 'type' => 'total']],
            'group_by' => [['facet' => 'status', 'limit' => 20]],
            'filter' => ['from' => '2026-10-22T00:00:00.000Z', 'to' => '2026-10-23T00:00:00.000Z', 'query' => 'app:svc env:stg'],
        ], $this->body($req));
    }

    public function testOnlyReadEndpointsArePosted(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('totals.json')), new Response(200, [], $this->fixture('search_page2.json'))]);

        $source->totals($this->window(), $this->selector());
        $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 5)));

        $paths = array_map(static fn (array $h): string => $h['request']->getUri()->getPath(), $this->history);
        $this->assertSame(['/api/v2/logs/analytics/aggregate', '/api/v2/logs/events/search'], $paths);
    }

    public function testTagValuesWithSpacesOrColonsAreQuotedAndEscaped(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('aggregate_empty.json'))]);

        $source->totals($this->window(), new StreamSelector(['app' => 'orders-api', 'team' => 'a b', 'url' => 'x:y"z']));

        $this->assertSame('app:orders-api team:"a b" url:"x:y\"z"', $this->body($this->history[0]['request'])['filter']['query']);
    }

    public function testInvalidTagKeysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->source([])->totals($this->window(), new StreamSelector(['app OR x' => 'v']));
    }

    public function testInvalidSiteIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatadogSource(new Client(), 'evil.com/x?', 'a', 'b');
    }

    public function testLinesRequestHasStatusAndLiteralWatchFilter(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('search_page2.json'))]);

        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, ['/timeout/i'], 10)));

        $this->assertSame(['slow call'], array_map(static fn (LogEvent $e): string => $e->message, $events));
        $body = $this->body($this->history[0]['request']);
        $this->assertSame('app:svc env:stg AND (status:(warn OR error OR critical OR alert OR emergency) OR "timeout")', $body['filter']['query']);
        $this->assertSame('timestamp', $body['sort']);
        $this->assertSame(['limit' => 1000], $body['page']);
    }

    public function testRegexWatchPatternDisablesServerFilterButStillAppliesClientSide(): void
    {
        $page = $this->page([$this->line('INFO', 'timeout 42'), $this->line('INFO', 'quiet')], null);
        $source = $this->source([$page]);

        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, ['/timeout \d+/'], 10)));

        $this->assertSame('app:svc env:stg', $this->body($this->history[0]['request'])['filter']['query']);
        $this->assertSame(['timeout 42'], array_map(static fn (LogEvent $e): string => $e->message, $events));
    }

    public function testNoServerFilterWhenEverythingIsWanted(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('search_page2.json'))]);

        $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Debug, [], 10)));

        $this->assertSame('app:svc env:stg', $this->body($this->history[0]['request'])['filter']['query']);
    }

    public function testNothingIsRequestedUntilTheGeneratorIsConsumed(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('search_page2.json'))]);

        $source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 10));

        $this->assertSame([], $this->history);
    }

    public function testCursorPagingFollowsMetaPageAfterAndMapsEvents(): void
    {
        $source = $this->source([
            new Response(200, [], $this->fixture('search_page1.json')),
            new Response(200, [], $this->fixture('search_page2.json')),
        ]);

        /** @var list<LogEvent> $events */
        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 10)));

        $this->assertSame(['Payment failed', 'DB down', 'slow call'], array_map(static fn (LogEvent $e): string => $e->message, $events));
        $this->assertSame([Level::Error, Level::Critical, Level::Warning], array_map(static fn (LogEvent $e): Level => $e->level, $events));
        $this->assertSame(['app' => 'orders-api', 'env' => 'stg', 'pod' => 'a', 'service' => 'orders-api'], $events[0]->labels);
        $this->assertSame('2026-10-22T10:05:00+00:00', $events[1]->time->format('c'));
        $this->assertCount(2, $this->history);
        $this->assertSame(['limit' => 1000, 'cursor' => 'cursor-2'], $this->body($this->history[1]['request'])['page']);
    }

    public function testEventCapStopsPagingEarly(): void
    {
        $source = $this->makeSourceWithManyLines(2500);

        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Warning, [], 1500)));

        $this->assertCount(1500, $events);
        $this->assertTrue($source->lastReadTruncated());
        $this->assertCount(2, $this->history);
    }

    public function testStatusFillsTheLevelWhenTheMessageHasNone(): void
    {
        $source = $this->source([$this->json(['data' => [
            ['attributes' => ['timestamp' => '2026-10-22T10:00:00.000Z', 'status' => 'error', 'message' => 'plain text']],
        ]])]);

        $events = $this->collect($source->lines($this->window(), $this->selector(), new ReadFilter(Level::Error, [], 10)));

        $this->assertSame(Level::Error, $events[0]->level);
    }

    public function testVersionsRequestAndFixtureMapping(): void
    {
        $source = $this->source([new Response(200, [], $this->fixture('versions.json'))]);

        $got = $source->versions($this->window(), $this->selector(), 'version');

        // zero-count series and the null-valued group are skipped; ordered by first seen
        $this->assertSame(['1.4.0', '1.5.0'], array_keys($got));
        $this->assertSame('2026-10-22T01:00:00+00:00', $got['1.4.0']->format('c'));
        $this->assertSame('2026-10-22T11:00:00+00:00', $got['1.5.0']->format('c'));
        $body = $this->body($this->history[0]['request']);
        $this->assertSame([['aggregation' => 'count', 'type' => 'timeseries', 'interval' => '1h']], $body['compute']);
        $this->assertSame([['facet' => 'version', 'limit' => 50]], $body['group_by']);
    }

    public function testRateLimitWaitsForResetAndRetries(): void
    {
        $source = $this->source([
            new Response(429, ['X-RateLimit-Reset' => '7'], '{}'),
            new Response(429, ['X-RateLimit-Reset' => '900'], '{}'),
            new Response(200, [], $this->fixture('totals.json')),
        ]);

        $totals = $source->totals($this->window(), $this->selector());

        $this->assertSame(907, $totals['info']);
        $this->assertSame([7, 60], $this->slept); // second wait is capped
        $this->assertCount(3, $this->history);
    }

    public function testRateLimitGivesUpAfterThreeRetries(): void
    {
        $source = $this->source(array_fill(0, 5, new Response(429, ['X-RateLimit-Reset' => '1'], '{}')));

        try {
            $source->totals($this->window(), $this->selector());
            $this->fail('SourceUnavailable expected');
        } catch (SourceUnavailable $e) {
            $this->assertSame('datadog 429 Too Many Requests', $e->getMessage());
        }
        $this->assertCount(4, $this->history);
        $this->assertCount(3, $this->slept);
    }

    public function testErrorMessageNamesStatusOnly(): void
    {
        $source = $this->source([new Response(403, [], $this->fixture('error_forbidden.json'))]);

        try {
            $source->totals($this->window(), $this->selector());
            $this->fail('SourceUnavailable expected');
        } catch (SourceUnavailable $e) {
            $this->assertSame('datadog 403 Forbidden', $e->getMessage());
        }
    }

    public function testAppKeyNeverAppearsInANetworkErrorMessage(): void
    {
        $source = $this->source([new ConnectException('boom ' . self::APP_KEY, new Request('POST', 'https://api.datadoghq.eu/x'))]);

        try {
            $source->totals($this->window(), $this->selector());
            $this->fail('SourceUnavailable expected');
        } catch (SourceUnavailable $e) {
            $this->assertStringNotContainsString(self::APP_KEY, $e->getMessage());
            $this->assertStringNotContainsString('datadoghq', $e->getMessage());
        }
    }

    // helpers

    /** @param list<Response|ConnectException> $responses */
    private function source(array $responses): DatadogSource
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new DatadogSource(
            new Client(['handler' => $stack]),
            'datadoghq.eu',
            self::API_KEY,
            self::APP_KEY,
            function (int $s): void {
                $this->slept[] = $s;
            },
        );
    }

    /** @return array<string, mixed> */
    private function body(RequestInterface $request): array
    {
        $data = json_decode((string) $request->getBody(), true);

        return is_array($data) ? $data : [];
    }

    /** @param list<string> $messages */
    private function page(array $messages, ?string $after): Response
    {
        $data = [];
        foreach ($messages as $i => $m) {
            $data[] = ['id' => "id{$i}", 'type' => 'log', 'attributes' => [
                'timestamp' => '2026-10-22T10:00:00.000Z', 'service' => 'svc', 'tags' => ['app:svc', 'env:stg'], 'message' => $m,
            ]];
        }

        return $this->json(['data' => $data, 'meta' => ['page' => $after === null ? new \stdClass() : ['after' => $after]]]);
    }

    /** @param array<mixed> $body */
    private function json(array $body): Response
    {
        return new Response(200, [], (string) json_encode($body));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/datadog/' . $name);
    }
}
