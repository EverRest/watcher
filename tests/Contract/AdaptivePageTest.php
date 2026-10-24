<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Watcher\Level;
use Watcher\Schedule\Window;
use Watcher\Source\DatadogSource;
use Watcher\Source\LokiSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;

/** R-1: a page over the response cap is retried at the same cursor with half the limit instead of failing the source. */
final class AdaptivePageTest extends TestCase
{
    private const T0 = 1792627200;
    private const LINE = 3400; // 5000 of these are a 17 MB body

    private function window(): Window
    {
        return new Window(new DateTimeImmutable('@' . self::T0), new DateTimeImmutable('@' . (self::T0 + 86400)));
    }

    private function filter(): ReadFilter
    {
        return new ReadFilter(Level::Error, [], 10000);
    }

    /** @param list<int> $asked A Loki that returns `limit` fat error lines for the first page and nothing after; records every limit asked. */
    private function loki(array &$asked, int $maxBytes = 16 * 1024 * 1024): LokiSource
    {
        $calls = static function (RequestInterface $r, array $o) use (&$asked) {
            parse_str($r->getUri()->getQuery(), $q);
            $asked[] = (int) $q['limit'];

            return \GuzzleHttp\Promise\Create::promiseFor(self::lokiPage((int) $q['limit'], (is_string($q['start']) ? $q['start'] : '') === (string) (self::T0 * 1_000_000_000)));
        };

        return new LokiSource(new Client(['handler' => $calls]), 'https://loki.test', 't', sleeper: static fn (int $s) => null, maxResponseBytes: $maxBytes);
    }

    private static function lokiPage(int $n, bool $first): Response
    {
        $values = [];
        for ($i = 0; $first && $i < $n; ++$i) {
            $values[] = [(string) ((self::T0 + $i) * 1_000_000_000), 'ERROR ' . $i . ' ' . str_repeat('x', self::LINE)];
        }

        return new Response(200, [], (string) json_encode(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => $values === [] ? [] : [['stream' => ['app' => 's'], 'values' => $values]]]]));
    }

    public function testLokiHalvesTheLimitAtTheSameCursorWhenAPageTripsTheCap(): void
    {
        $asked = [];
        $src = $this->loki($asked);
        $events = iterator_to_array($src->lines($this->window(), new StreamSelector(['app' => 's']), $this->filter()), false);

        $this->assertSame([5000, 2500], array_slice($asked, 0, 2));
        $this->assertCount(2500, $events);
        $this->assertSame(1, $src->pagesShrunk);
        $this->assertSame(1, $src->readDiagnostics()['pages_shrunk'] ?? null);
    }

    public function testLokiFailsWithTheExistingMessageWhenEvenTheSmallestPageTrips(): void
    {
        $asked = [];
        $src = $this->loki($asked, 1000); // a 1 KB cap: a single 3.4 KB line cannot fit
        try {
            iterator_to_array($src->lines($this->window(), new StreamSelector(['app' => 's']), $this->filter()), false);
            $this->fail('expected SourceUnavailable');
        } catch (SourceUnavailable $e) {
            $this->assertSame('loki response too large', $e->getMessage());
        }
        $this->assertSame([5000, 2500, 1250, 625, 312, 156, 100], $asked);
    }

    public function testLokiCapIsConfigurable(): void
    {
        $asked = [];
        $src = $this->loki($asked, 64 * 1024 * 1024);
        $events = iterator_to_array($src->lines($this->window(), new StreamSelector(['app' => 's']), $this->filter()), false);
        $this->assertSame([5000, 5000], $asked);
        $this->assertCount(5000, $events);
        $this->assertSame(0, $src->pagesShrunk);
    }

    /** @param list<int> $asked */
    private function datadog(array &$asked, int $maxBytes = 16 * 1024 * 1024): DatadogSource
    {
        $calls = static function (RequestInterface $r, array $o) use (&$asked) {
            $body = json_decode((string) $r->getBody(), true);
            $n = (int) $body['page']['limit'];
            $asked[] = $n;
            $data = [];
            for ($i = 0; $i < $n; ++$i) {
                $data[] = ['attributes' => ['timestamp' => '2026-10-22T00:00:01.000Z', 'status' => 'error', 'message' => 'ERROR ' . $i . ' ' . str_repeat('x', self::LINE), 'tags' => []]];
            }

            return \GuzzleHttp\Promise\Create::promiseFor(new Response(200, [], (string) json_encode(['data' => $data])));
        };

        return new DatadogSource(new Client(['handler' => $calls]), 'datadoghq.com', 'a', 'b', maxResponseBytes: $maxBytes);
    }

    public function testDatadogHalvesTheLimitWhenAPageTripsTheCap(): void
    {
        $asked = [];
        $src = $this->datadog($asked);
        $events = iterator_to_array($src->lines($this->window(), new StreamSelector(['service' => 's']), $this->filter()), false);

        $this->assertSame([5000, 2500], $asked);
        $this->assertCount(2500, $events);
        $this->assertSame(1, $src->pagesShrunk);
        $this->assertSame(1, $src->readDiagnostics()['pages_shrunk'] ?? null);
    }

    public function testDatadogFailsWhenEvenTheSmallestPageTrips(): void
    {
        $asked = [];
        $src = $this->datadog($asked, 1000);
        $this->expectException(SourceUnavailable::class);
        $this->expectExceptionMessage('datadog response too large');
        try {
            iterator_to_array($src->lines($this->window(), new StreamSelector(['service' => 's']), $this->filter()), false);
        } finally {
            $this->assertSame([5000, 2500, 1250, 625, 312, 156, 100], $asked);
        }
    }
}
