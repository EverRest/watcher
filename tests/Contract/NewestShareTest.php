<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Watcher\Level;
use Watcher\Schedule\Window;
use Watcher\Source\DatadogSource;
use Watcher\Source\LogEvent;
use Watcher\Source\LogSource;
use Watcher\Source\LokiSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\StreamSelector;

/** A capped read keeps a share of the NEWEST events (A4-03): today's incident must not hide behind the oldest ones. */
final class NewestShareTest extends TestCase
{
    private const START = 1792627200; // 2026-10-22T00:00:00Z, the window start

    public function testReadFilterReservesAFifthOfTheCapForTheNewest(): void
    {
        $filter = new ReadFilter(Level::Error, [], 1000);
        $this->assertSame(200, $filter->tailCap());
        $this->assertSame(800, $filter->headCap());
        $tiny = new ReadFilter(Level::Error, [], 4);
        $this->assertSame(0, $tiny->tailCap(), 'a cap under 5 has no share: the old oldest-first behaviour');
        $this->assertSame(4, $tiny->headCap());
    }

    /** @return iterable<string, array{string}> */
    public static function sources(): iterable
    {
        yield 'loki' => ['loki'];
        yield 'datadog' => ['datadog'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sources')]
    public function testAnIncidentInTheLastFivePercentIsSeenWhenTheCapIsAFifthOfTheEvents(string $kind): void
    {
        $source = $this->source($kind, self::eventsIn(5000, 4750));

        $events = $this->read($source, 1000);

        $this->assertLessThanOrEqual(1000, count($events));
        $this->assertGreaterThan(0, count(array_filter($events, static fn (LogEvent $e): bool => str_contains($e->message, 'IncidentPayment'))), 'the incident at the end is read');
        $this->assertSame('e0', $events[0]->message, 'the oldest head is still read');
        $times = array_map(static fn (LogEvent $e): int => $e->time->getTimestamp(), $events);
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, 'events stay oldest to newest');
        $this->assertSame(count($times), count(array_unique($times)), 'no event twice');
        $this->assertTrue($source->lastReadTruncated());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sources')]
    public function testAReadBetweenTheHeadAndTheCapIsStillComplete(string $kind): void
    {
        $source = $this->source($kind, self::eventsIn(950, 900));

        $events = $this->read($source, 1000);

        $this->assertCount(950, $events);
        $this->assertFalse($source->lastReadTruncated(), 'nothing was lost, so nothing is reported as cut');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sources')]
    public function testACapReportsItsReason(string $kind): void
    {
        $source = $this->source($kind, self::eventsIn(5000, 4750));
        $filter = new ReadFilter(Level::Error, [], 1000);
        iterator_to_array($source->lines($this->window(), new StreamSelector(['app' => 'svc']), $filter), false);

        $this->assertSame('cap', $filter->truncationReason);
    }

    public function testASpentPageBudgetStillReadsTheNewestShare(): void
    {
        $client = new Client(['handler' => static fn (RequestInterface $r, array $o) => Create::promiseFor(self::loki($r, self::eventsIn(12000, 11900)))]);
        $source = new LokiSource($client, 'https://loki.test/', 'tok', maxPages: 1);
        $filter = new ReadFilter(Level::Error, [], 10000);

        $events = (iterator_to_array($source->lines($this->window(), new StreamSelector(['app' => 'svc']), $filter), false));

        $this->assertNotEmpty(array_filter($events, static fn (LogEvent $e): bool => str_contains($e->message, 'IncidentPayment')));
        $this->assertSame('pages', $filter->truncationReason);
    }

    /** @return list<LogEvent> */
    private function read(LogSource $source, int $cap): array
    {
        return (iterator_to_array($source->lines($this->window(), new StreamSelector(['app' => 'svc']), new ReadFilter(Level::Error, [], $cap)), false));
    }

    private function window(): Window
    {
        return Window::endingAt(new DateTimeImmutable('2026-10-23T00:00:00Z'));
    }

    /**
     * @return list<array{int, string}> [unix seconds, message], oldest first; every event at or after $incidentFrom is the incident
     */
    private static function eventsIn(int $n, int $incidentFrom): array
    {
        $out = [];
        for ($i = 0; $i < $n; ++$i) {
            $out[] = [self::START + $i * intdiv(80000, $n), $i >= $incidentFrom ? "IncidentPayment {$i}" : "e{$i}"];
        }

        return $out;
    }

    public function testADatadogTailThatIsTooLargeShrinksItsPageLikeTheHeadDoes(): void
    {
        $source = $this->tooLargeSource(self::eventsIn(5000, 4750), static fn (array $b): bool => $b['sort'] === '-timestamp' && $b['page']['limit'] > 1000);

        $events = $this->read($source, 1000);

        $this->assertNotEmpty(array_filter($events, static fn (LogEvent $e): bool => str_contains($e->message, 'IncidentPayment')));
        $this->assertArrayHasKey('pages_shrunk', $source->readDiagnostics());
    }

    public function testADatadogTailThatCannotFitTheResponseCapDegradesToATruncatedReadInsteadOfFailing(): void
    {
        $source = $this->tooLargeSource(self::eventsIn(5000, 4750), static fn (array $b): bool => $b['sort'] === '-timestamp');
        $filter = new ReadFilter(Level::Error, [], 1000);

        $events = iterator_to_array($source->lines($this->window(), new StreamSelector(['app' => 'svc']), $filter), false);

        $this->assertCount(800, $events, 'the head that was already read is kept');
        $this->assertTrue($source->lastReadTruncated());
        $this->assertSame('cap', $filter->truncationReason);
    }

    public function testDatadogEventsInTheSameMillisecondAsTheSeamAreReportedAsACut(): void
    {
        $events = self::eventsIn(900, 900);
        $events[800][0] = $events[799][0]; // the event that trips the cap shares its time with the last one kept
        $source = $this->source('datadog', $events);

        $this->read($source, 1000);

        $this->assertTrue($source->lastReadTruncated());
    }

    /**
     * @param list<array{int, string}>      $events
     * @param callable(array<mixed>): bool $tooLarge decides, from the request body, that the reply is over the response cap
     */
    private function tooLargeSource(array $events, callable $tooLarge): DatadogSource
    {
        $handler = static function (RequestInterface $r, array $o) use ($events, $tooLarge) {
            $body = json_decode((string) $r->getBody(), true);
            if ($tooLarge($body)) {
                return Create::promiseFor(new Response(200, [], (string) json_encode(['data' => [], 'pad' => str_repeat('x', 300_000)])));
            }

            return Create::promiseFor(self::datadog($r, $events));
        };

        return new DatadogSource(new Client(['handler' => $handler]), 'datadoghq.eu', 'k', 'a', maxResponseBytes: 200_000);
    }

    /** @param list<array{int, string}> $events */
    private function source(string $kind, array $events): LogSource
    {
        $handler = static fn (RequestInterface $r, array $o) => Create::promiseFor($kind === 'loki' ? self::loki($r, $events) : self::datadog($r, $events));
        $client = new Client(['handler' => $handler]);

        return $kind === 'loki'
            ? new LokiSource($client, 'https://loki.test/', 'tok')
            : new DatadogSource($client, 'datadoghq.eu', 'k', 'a');
    }

    /** @param list<array{int, string}> $events */
    private static function loki(RequestInterface $r, array $events): Response
    {
        parse_str($r->getUri()->getQuery(), $q);
        $start = (int) $q['start'];
        $end = (int) $q['end'];
        $values = [];
        foreach ($events as [$t, $m]) {
            $ns = $t * 1_000_000_000;
            if ($ns >= $start && $ns < $end) {
                $values[] = [(string) $ns, "[" . gmdate('Y-m-d H:i:s', $t) . "] production.ERROR: {$m} {}"];
            }
        }
        if ($q['direction'] === 'backward') {
            $values = array_reverse($values);
        }
        $values = array_slice($values, 0, (int) $q['limit']);

        return new Response(200, [], (string) json_encode(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [['stream' => ['app' => 'svc'], 'values' => $values]]]]));
    }

    /** @param list<array{int, string}> $events */
    private static function datadog(RequestInterface $r, array $events): Response
    {
        $body = json_decode((string) $r->getBody(), true);
        $from = (new DateTimeImmutable($body['filter']['from']))->format('Uv') / 1;
        $to = (new DateTimeImmutable($body['filter']['to']))->format('Uv') / 1;
        $rows = [];
        foreach ($events as [$t, $m]) {
            if ($t * 1000 >= $from && $t * 1000 <= $to) {
                $rows[] = ['attributes' => [
                    'timestamp' => gmdate('Y-m-d\TH:i:s', $t) . '.000Z',
                    'message' => "[" . gmdate('Y-m-d H:i:s', $t) . "] production.ERROR: {$m} {}",
                    'status' => 'error',
                    'service' => 'svc',
                    'tags' => [],
                ]];
            }
        }
        if ($body['sort'] === '-timestamp') {
            $rows = array_reverse($rows);
        }
        $offset = isset($body['page']['cursor']) ? (int) $body['page']['cursor'] : 0;
        $limit = (int) $body['page']['limit'];
        $page = array_slice($rows, $offset, $limit);
        $after = $offset + $limit < count($rows) ? (string) ($offset + $limit) : null;

        return new Response(200, [], (string) json_encode(['data' => $page, 'meta' => ['page' => $after === null ? new \stdClass() : ['after' => $after]]]));
    }
}
