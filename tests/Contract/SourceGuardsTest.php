<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Schedule\Window;
use Watcher\Source\DatadogSource;
use Watcher\Source\LokiSource;
use Watcher\Source\ReadFilter;
use Watcher\Source\ResponseLimit;
use Watcher\Source\SourceUnavailable;
use Watcher\Source\StreamSelector;
use Watcher\Tests\Fakes\FrozenClock;

/** A4-01/04/10: paging budgets, progress guard, oversized lines and response size, for both backends. */
final class SourceGuardsTest extends TestCase
{
    public const T0 = 1792627200;

    private function window(): Window
    {
        return new Window(new DateTimeImmutable('@' . self::T0), new DateTimeImmutable('@' . (self::T0 + 86400)));
    }

    /**
     * @param list<Response> $responses
     * @param-out MockHandler $mock
     */
    private function loki(array $responses, ?FrozenClock $clock = null, int $pagingBudget = 300, int $maxPages = 1000, ?MockHandler &$mock = null): LokiSource
    {
        $mock = new MockHandler($responses);

        return new LokiSource(new Client(['handler' => HandlerStack::create($mock)]), 'https://loki.test', 't', sleeper: static fn (int $s) => null, clock: $clock, pagingBudget: $pagingBudget, maxPages: $maxPages);
    }

    /** @param list<array{string, string}> $values */
    private function page(array $values): Response
    {
        return new Response(200, [], (string) json_encode(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [['stream' => ['app' => 's'], 'values' => $values]]]]));
    }

    /** @return list<array{string, string}> */
    private function infoPage(int $from): array
    {
        $v = [];
        for ($i = 0; $i < 5000; ++$i) {
            $v[] = [(string) ((self::T0 + $from + $i) * 1_000_000_000), 'INFO nothing to see'];
        }

        return $v;
    }

    private function filter(int $cap = 100): ReadFilter
    {
        return new ReadFilter(Level::Error, [], $cap);
    }

    public function testEndlessUnwantedPagesStopAtThePagesCapAndReportTruncated(): void
    {
        $pages = [];
        for ($p = 0; $p < 6; ++$p) {
            $pages[] = $this->page($this->infoPage($p * 5000));
        }
        $src = $this->loki($pages, maxPages: 3);
        $f = $this->filter();

        $this->assertSame([], iterator_to_array($src->lines($this->window(), new StreamSelector(['app' => 's']), $f), false));
        $this->assertTrue($src->lastReadTruncated());
        $this->assertSame('pages', $f->truncationReason);
    }

    public function testPagingStopsAtTheWallTimeBudget(): void
    {
        $clock2 = new FrozenClock(new DateTimeImmutable('@' . self::T0));
        $calls = 0;
        $slow = new Client(['handler' => HandlerStack::create(function () use ($clock2, &$calls) {
            ++$calls;
            $clock2->advance('+301 seconds');

            return \GuzzleHttp\Promise\Create::promiseFor($this->page($this->infoPage($calls * 5000)));
        })]);
        $src = new LokiSource($slow, 'https://loki.test', 't', clock: $clock2, pagingBudget: 300);
        $f = $this->filter();
        iterator_to_array($src->lines($this->window(), new StreamSelector(['app' => 's']), $f), false);
        $this->assertSame(1, $calls);
        $this->assertSame('time', $f->truncationReason);
    }

    public function testABackendThatIgnoresStartIsStoppedByTheCursorGuard(): void
    {
        $same = $this->infoPage(0);
        $src = $this->loki([$this->page($same), $this->page($same), $this->page($same)], maxPages: 50);
        $f = $this->filter();
        iterator_to_array($src->lines($this->window(), new StreamSelector(['app' => 's']), $f), false);
        $this->assertTrue($src->lastReadTruncated());
        $this->assertSame('cursor', $f->truncationReason);
    }

    public function testOversizedLinesAreDroppedBeforeTheParserAndCounted(): void
    {
        $big = '[2026-10-22 00:00:01] local.ERROR: ' . str_repeat('a', ReadFilter::MAX_LINE_BYTES);
        $src = $this->loki([$this->page([[(string) (self::T0 * 1_000_000_000 + 1), $big], [(string) (self::T0 * 1_000_000_000 + 2), 'ERROR small']])]);
        $f = $this->filter();
        $events = iterator_to_array($src->lines($this->window(), new StreamSelector(['app' => 's']), $f), false);
        $this->assertCount(1, $events);
        $this->assertSame(1, $f->oversizedLines);
    }

    public function testTheStopHookEndsTheReadWithItsReason(): void
    {
        $src = $this->loki([$this->page($this->infoPage(0)), $this->page($this->infoPage(5000))]);
        $f = $this->filter();
        $f->stopWhen(static fn (): string => 'deadline');
        iterator_to_array($src->lines($this->window(), new StreamSelector(['app' => 's']), $f), false);
        $this->assertTrue($src->lastReadTruncated());
        $this->assertSame('deadline', $f->truncationReason);
    }

    public function testALargeContentLengthIsRefusedWithoutBeingRead(): void
    {
        $r = new Response(200, ['Content-Length' => (string) (ResponseLimit::MAX_BYTES + 1)], '{}');
        $src = $this->loki([$r]);
        $this->expectException(SourceUnavailable::class);
        $this->expectExceptionMessage('response too large');
        $src->totals($this->window(), new StreamSelector(['app' => 's']));
    }

    public function testTheHeadersHookAbortsOnlyAboveTheCapAndSaysWhy(): void
    {
        /** @var \ArrayObject<string, bool> $tripped */
        $tripped = new \ArrayObject();
        $hook = ResponseLimit::options($tripped, 1000)['on_headers'];

        $hook(new Response(200, ['Content-Length' => '1000'], ''));
        $this->assertCount(0, $tripped);

        try {
            $hook(new Response(200, ['Content-Length' => '1001'], ''));
            $this->fail('expected the abort');
        } catch (SourceUnavailable $e) {
            $this->assertTrue($e->tooLarge);
            $this->assertTrue($tripped['large']);
        }
    }

    public function testAnOversizedBodyWithoutContentLengthIsRefused(): void
    {
        $body = '{"data":{"result":[]},"pad":"' . str_repeat('x', ResponseLimit::MAX_BYTES) . '"}';
        $src = $this->loki([new Response(200, [], $body)]);
        $this->expectException(SourceUnavailable::class);
        $this->expectExceptionMessage('response too large');
        $src->totals($this->window(), new StreamSelector(['app' => 's']));
    }

    public function testJsonNestedDeeperThanSixtyFourIsNotParsed(): void
    {
        $src = $this->loki([new Response(200, [], str_repeat('[', 100) . str_repeat(']', 100))]);
        $this->expectException(SourceUnavailable::class);
        $this->expectExceptionMessage('non-JSON');
        $src->totals($this->window(), new StreamSelector(['app' => 's']));
    }

    public function testDatadogNeverFollowsARedirectAndPinsTls(): void
    {
        $hist = [];
        $stack = HandlerStack::create(new MockHandler([new Response(302, ['Location' => 'https://evil.example/']), new Response(200, [], '{"data":[]}')]));
        $stack->push(\GuzzleHttp\Middleware::history($hist));
        $src = new DatadogSource(new Client(['handler' => $stack]), 'datadoghq.com', 'a', 'b', sleeper: static fn (int $s) => null);

        try {
            $src->totals($this->window(), new StreamSelector(['service' => 's']));
        } catch (SourceUnavailable) {
        }
        $this->assertIsArray($hist);
        $this->assertCount(1, $hist);
        $this->assertFalse($hist[0]['options']['allow_redirects']);
        $this->assertTrue($hist[0]['options']['verify']);
    }

    public function testDatadogDropsOversizedMessagesAndHonoursTheStopHook(): void
    {
        $ev = static fn (string $m) => ['attributes' => ['timestamp' => '2026-10-22T00:00:01.000Z', 'status' => 'error', 'message' => $m, 'tags' => []]];
        $mock = new MockHandler([new Response(200, [], (string) json_encode(['data' => [$ev(str_repeat('b', ReadFilter::MAX_LINE_BYTES + 1)), $ev('ok')], 'meta' => ['page' => ['after' => 'c']]])), new Response(200, [], (string) json_encode(['data' => [$ev('later')]]))]);
        $src = new DatadogSource(new Client(['handler' => HandlerStack::create($mock)]), 'datadoghq.com', 'a', 'b');
        $f = $this->filter();
        $f->stopWhen(static fn (): ?string => $mock->count() === 1 ? 'memory' : null);
        $events = iterator_to_array($src->lines($this->window(), new StreamSelector(['service' => 's']), $f), false);
        $this->assertCount(1, $events);
        $this->assertSame(1, $f->oversizedLines);
        $this->assertSame('memory', $f->truncationReason);
        $this->assertTrue($src->lastReadTruncated());
    }
}
