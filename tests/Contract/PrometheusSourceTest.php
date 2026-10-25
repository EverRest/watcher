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
use Watcher\Metrics\MetricSource;
use Watcher\Metrics\PrometheusSource;
use Watcher\Source\SourceUnavailable;

final class PrometheusSourceTest extends MetricSourceContract
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

    protected function makeSourceWithSamples(array $raw): MetricSource
    {
        $result = [];
        foreach ($raw as $i => $v) {
            $result[] = ['metric' => ['i' => (string) $i], 'value' => [1792713600, $v]];
        }

        return $this->source([new Response(200, [], (string) json_encode(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $result]]))]);
    }

    protected function makeSourceFailing(int $httpStatus): MetricSource
    {
        $r = $httpStatus === 0
            ? new ConnectException('cannot connect to https://prom.test/?token=' . self::SECRET, new Request('GET', 'https://prom.test/'))
            : new Response($httpStatus, [], $this->fixture('error_401.json'));

        return $this->source(array_fill(0, 3, $r));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/prometheus/' . $name);
    }

    /**
     * @param list<Response|\Throwable> $queue
     * @param list<string>              $allow
     */
    private function source(array $queue, ?string $uid = null, array $allow = [], int $maxPoints = 500): PrometheusSource
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new PrometheusSource(new Client(['handler' => $stack]), 'https://prom.test/', self::SECRET, $uid, $allow, $maxPoints, static function (int $s): void {
        });
    }

    /** @return array<int|string, string> */
    private function query(RequestInterface $r): array
    {
        $raw = $r->getMethod() === 'POST' ? (string) $r->getBody() : $r->getUri()->getQuery();
        parse_str($raw, $out);

        return array_filter($out, 'is_string');
    }

    public function testInstantIsAGetWithQueryTimeAndBearer(): void
    {
        $s = $this->source([new Response(200, [], $this->fixture('vector_one.json'))]);

        $r = $s->instant('up', $this->at());

        self::assertSame([0.004], $r->values);
        $req = $this->history[0]['request'];
        self::assertSame('GET', $req->getMethod());
        self::assertSame('/api/v1/query', $req->getUri()->getPath());
        self::assertSame(['query' => 'up', 'time' => '1792713600'], $this->query($req));
        self::assertSame('Bearer ' . self::SECRET, $req->getHeaderLine('Authorization'));
    }

    public function testGrafanaTypeUsesTheProxyPrefix(): void
    {
        $s = $this->source([new Response(200, [], $this->fixture('vector_one.json'))], 'prom-uid');

        $s->instant('up', $this->at());

        self::assertSame('/api/datasources/proxy/uid/prom-uid/api/v1/query', $this->history[0]['request']->getUri()->getPath());
    }

    public function testLongQueryIsAFormPostOnTheSamePath(): void
    {
        $s = $this->source([new Response(200, [], $this->fixture('vector_one.json'))]);
        $q = 'sum(' . str_repeat('a', 2100) . ')';

        $s->instant($q, $this->at());

        $req = $this->history[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('/api/v1/query', $req->getUri()->getPath());
        self::assertStringContainsString('application/x-www-form-urlencoded', $req->getHeaderLine('Content-Type'));
        self::assertSame($q, $this->query($req)['query']);
    }

    public function testLabelsAreDroppedAndOnlyFloatsKept(): void
    {
        $r = $this->source([new Response(200, [], $this->fixture('vector_labelled.json'))])->instant('q', $this->at());

        self::assertSame([2.0, 3.5], $r->values);
        self::assertSame(2, $r->series);
        self::assertSame([], $r->allowedLabelValues);
    }

    public function testAllowlistedLabelValuesSurviveCappedAtFiveDistinct(): void
    {
        $result = [];
        foreach (['a', 'b', 'a', 'c', 'd', 'e', 'f', 'g'] as $i => $v) {
            $result[] = ['metric' => ['code' => $v, 'customer' => 'x' . $i], 'value' => [1, '1']];
        }
        $body = (string) json_encode(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $result]]);

        $r = $this->source([new Response(200, [], $body)], null, ['code'])->instant('q', $this->at());

        self::assertSame(['a', 'b', 'c', 'd', 'e'], $r->allowedLabelValues);
    }

    public function testNanAndInfAreFilteredAndEmptyIsNoData(): void
    {
        $r = $this->source([new Response(200, [], $this->fixture('vector_nan.json'))])->instant('q', $this->at());
        self::assertSame([7.0], $r->values);
        self::assertSame(4, $r->series);

        $e = $this->source([new Response(200, [], $this->fixture('vector_empty.json'))])->instant('q', $this->at());
        self::assertSame([], $e->values);
    }

    public function testRangeReturnsSamplesAndTimestampsOfASingleSeries(): void
    {
        $s = $this->source([new Response(200, [], $this->fixture('matrix_one.json'))]);
        $start = new DateTimeImmutable('2026-10-21T00:00:00Z');
        $end = new DateTimeImmutable('2026-10-23T00:00:00Z');

        $r = $s->range('q', $start, $end, 3600);

        self::assertSame([1.0, 2.0, 4.0], $r->values);
        self::assertSame([1792540800, 1792544400, 1792630800], $r->timestamps);
        self::assertSame(1, $r->series);
        $req = $this->history[0]['request'];
        self::assertSame('/api/v1/query_range', $req->getUri()->getPath());
        self::assertSame(['query' => 'q', 'start' => '1792540800', 'end' => '1792713600', 'step' => '3600'], $this->query($req));
    }

    public function testRangeIsRefusedClientSideWhenTooManyPoints(): void
    {
        $s = $this->source([], null, [], 10);

        $this->expectException(InvalidArgumentException::class);
        $s->range('q', new DateTimeImmutable('2026-10-21T00:00:00Z'), new DateTimeImmutable('2026-10-23T00:00:00Z'), 60);
    }

    public function testStatusMapsToTokens(): void
    {
        $cases = [
            [new Response(200, [], 'not json'), 'bad_response'],
            [new Response(200, [], $this->fixture('error_bad_query.json')), 'bad_response'],
            [new Response(400, [], $this->fixture('error_bad_query.json')), 'bad_response'],
            [new Response(413, [], ''), 'too_large'],
            [new ConnectException('cURL error 28: Operation timed out after 15000 ms', new Request('GET', 'https://prom.test/')), 'timeout'],
        ];
        foreach ($cases as [$response, $token]) {
            try {
                $this->source(array_fill(0, 3, $response))->instant('q', $this->at());
                self::fail('expected SourceUnavailable');
            } catch (SourceUnavailable $e) {
                self::assertSame($token, $e->getMessage());
            }
        }
    }

    public function testRateLimitAndServerErrorsAreRetriedThenNamed(): void
    {
        try {
            $this->source(array_fill(0, 3, new Response(429, [], '')))->instant('q', $this->at());
            self::fail();
        } catch (SourceUnavailable $e) {
            self::assertSame('rate_limited', $e->getMessage());
        }
        self::assertCount(3, $this->history);

        $this->history = [];
        try {
            $this->source(array_fill(0, 3, new Response(503, [], '')))->instant('q', $this->at());
            self::fail();
        } catch (SourceUnavailable $e) {
            self::assertSame('unreachable', $e->getMessage());
        }
    }

    public function testMalformedSamplesAreSkippedNotCrashed(): void
    {
        foreach ([[[null, '1']], [['1' => '2']], [['x', '1']]] as $values) {
            $body = (string) json_encode(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [['metric' => [], 'values' => $values]]]]);

            $r = $this->source([new Response(200, [], $body)])->range('q', new DateTimeImmutable('2026-10-22T00:00:00Z'), new DateTimeImmutable('2026-10-23T00:00:00Z'), 3600);

            self::assertSame([], $r->values);
            self::assertSame([], $r->timestamps);
        }
    }
}
