<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Context;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Context\DependencyContextReader;
use Watcher\Context\DependencyPlan;
use Watcher\Level;
use Watcher\Privacy\RedactedGroup;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Schedule\Window;
use Watcher\Source\LogEvent;
use Watcher\Source\ReadFilter;
use Watcher\Source\SourceUnavailable;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class DependencyContextReaderTest extends TestCase
{
    private Window $window;
    private InMemoryStateStore $store;
    private DependencyContextReader $reader;

    protected function setUp(): void
    {
        $end = new DateTimeImmutable('2026-10-20T00:00:00Z');
        $this->window = Window::endingAt($end);
        $this->store = new InMemoryStateStore(new FrozenClock($end));
        $normalizer = new Normalizer(['payments']);
        $this->reader = new DependencyContextReader($normalizer, new Redactor($normalizer), $this->store, 3600, 3);
    }

    private function event(string $msg, Level $l = Level::Error): LogEvent
    {
        return new LogEvent(new DateTimeImmutable('2026-10-19T12:00:00Z'), $l, $msg, null, [], [], []);
    }

    /** @return array<string, array{stream_tags: array<string,string>, source: ?string}> */
    private function declared(string ...$names): array
    {
        $out = [];
        foreach ($names as $n) {
            $out[$n] = ['stream_tags' => ['app' => $n], 'source' => null];
        }

        return $out;
    }

    /**
     * @param list<string> $linesFor
     * @param array<string, bool> $first
     */
    private function plan(array $linesFor = [], array $first = []): DependencyPlan
    {
        return new DependencyPlan($linesFor, [], [], $first);
    }

    public function testQuietDayOneTotalsNoLines(): void
    {
        $a = new FakeLogSource(['error' => 1]);
        $b = new FakeLogSource(['error' => 2]);
        $res = $this->reader->read('svc', $this->declared('a', 'b'), fn (string $n) => $n === 'a' ? $a : $b, $this->plan(), $this->window, 5000);

        self::assertCount(1, $a->callsTo('totals'));
        self::assertCount(1, $b->callsTo('totals'));
        self::assertSame([], $a->callsTo('lines') + $b->callsTo('lines'));
        self::assertCount(2, $res->contexts);
        self::assertSame([], $res->failedDependencies);
        self::assertSame(['error' => 1], $res->contexts[0]->totals);
        self::assertSame([], $res->contexts[0]->topErrors);
    }

    public function testTriggeredDepReadsLinesWithErrorLevelAndCap(): void
    {
        $src = new FakeLogSource(['error' => 3], [$this->event('boom'), $this->event('info line', Level::Info)]);
        $res = $this->reader->read('svc', $this->declared('db'), fn () => $src, $this->plan(['db']), $this->window, 5000);

        $calls = $src->callsTo('lines');
        self::assertCount(1, $calls);
        /** @var ReadFilter $f */
        $f = $calls[0][2];
        self::assertSame(Level::Error, $f->minLevel);
        self::assertSame([], $f->watchPatterns);
        self::assertSame(5000, $f->eventCap);
        self::assertCount(1, $src->callsTo('totals'));
        self::assertCount(1, $res->contexts[0]->topErrors);
        self::assertInstanceOf(RedactedGroup::class, $res->contexts[0]->topErrors[0]);
    }

    public function testTemplatesComeBackMasked(): void
    {
        $src = new FakeLogSource(['error' => 3], [
            $this->event('call https://payments/charge failed for bob@example.org'),
            $this->event('call https://payments/charge failed for amy@example.org'),
            $this->event('call https://payments/charge failed for cat@example.org'),
        ]);
        $res = $this->reader->read('svc', $this->declared('db'), fn () => $src, $this->plan(['db']), $this->window, 5000);
        $t = (string) $res->contexts[0]->topErrors[0]->template;
        self::assertStringContainsString('<URL:payments>', $t);
        self::assertStringNotContainsString('example.org', $t);
    }

    public function testExactlyFiveKeptOfMany(): void
    {
        $words = ['alpha', 'bravo', 'charlie', 'delta', 'echo', 'foxtrot', 'golf'];
        $events = [];
        foreach ($words as $i => $w) {
            for ($n = 0; $n <= $i; $n++) {
                $events[] = $this->event("$w exploded while handling zebra");
            }
        }
        $src = new FakeLogSource(['error' => 28], $events);
        $res = $this->reader->read('svc', $this->declared('db'), fn () => $src, $this->plan(['db']), $this->window, 5000);

        self::assertCount(5, $res->contexts[0]->topErrors);
        self::assertSame(7, $res->contexts[0]->topErrors[0]->count);
    }

    public function testSourceOverrideUsedForThatDep(): void
    {
        $own = new FakeLogSource(['error' => 1]);
        $other = new FakeLogSource(['error' => 9]);
        $decl = $this->declared('a', 'b');
        $decl['b']['source'] = 'other';
        $res = $this->reader->read('svc', $decl, fn (string $n) => $n === 'b' ? $other : $own, $this->plan(), $this->window, 5000);

        self::assertCount(1, $other->callsTo('totals'));
        self::assertCount(1, $own->callsTo('totals'));
        self::assertSame(['error' => 9], $res->contexts[1]->totals);
    }

    public function testUnavailableDepDoesNotStopOthers(): void
    {
        $bad = new FakeLogSource();
        $bad->failWith(new SourceUnavailable('down'));
        $good = new FakeLogSource(['error' => 4]);
        $res = $this->reader->read('svc', $this->declared('bad', 'good'), fn (string $n) => $n === 'bad' ? $bad : $good, $this->plan(['bad']), $this->window, 5000);

        self::assertSame(['bad'], $res->failedDependencies);
        self::assertTrue($res->contexts[0]->unavailable);
        self::assertSame([], $res->contexts[0]->topErrors);
        self::assertFalse($res->contexts[1]->unavailable);
        self::assertSame(['error' => 4], $res->contexts[1]->totals);
    }

    public function testSnapshotWrittenWithTtl(): void
    {
        $src = new FakeLogSource(['error' => 4]);
        $this->reader->read('svc', $this->declared('db'), fn () => $src, $this->plan(), $this->window, 5000);

        $key = Keys::dependencySnapshot('svc', 'db', '2026-10-20T00:00:00Z');
        self::assertSame(['error' => 4], $this->store->get($key)['totals'] ?? null);
        self::assertSame(3600, $this->store->ttls()[$key]);
    }

    public function testFirstSightingAndPreviousTotalsFromPlan(): void
    {
        $src = new FakeLogSource(['error' => 4]);
        $res = $this->reader->read('svc', $this->declared('db'), fn () => $src, $this->plan([], ['db' => true]), $this->window, 5000, ['db' => null]);
        self::assertTrue($res->contexts[0]->firstSighting);
        self::assertNull($res->contexts[0]->previousTotals);
    }

    public function testNoSeededPrivacyValueIsStored(): void
    {
        $events = [];
        foreach (file(__DIR__ . '/../../Fixtures/privacy/corpus.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $events[] = $this->event($line);
        }
        $src = new FakeLogSource(['error' => count($events)], $events);
        $res = $this->reader->read('svc', $this->declared('db'), fn () => $src, $this->plan(['db']), $this->window, 5000);
        self::assertNotSame([], $res->contexts[0]->topErrors);

        /** @var list<string> $seeded */
        $seeded = require __DIR__ . '/../../Fixtures/privacy/seeded-values.php';
        $blob = json_encode($this->store->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . json_encode($res->contexts[0]->topErrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($seeded as $value) {
            self::assertStringNotContainsString($value, (string) $blob, $value);
        }
    }

    public function testDependencyTextUsesTheServicesMinimumClusterSize(): void
    {
        // Two identical events naming a person: a cluster of 2 is below the configured threshold of 3, so the
        // dependency's message text must be withheld exactly like the service's own.
        $src = new FakeLogSource(['error' => 2], [
            $this->event('Billing failed for Anna Kowalski'),
            $this->event('Billing failed for Anna Kowalski'),
        ]);

        $res = $this->reader->read('svc', $this->declared('billing'), fn () => $src, $this->plan(['billing']), $this->window, 5000);

        $top = $res->contexts[0]->topErrors;
        self::assertCount(1, $top);
        self::assertNull($top[0]->template);
        self::assertStringNotContainsString('Anna', (string) json_encode($top));
    }
}
