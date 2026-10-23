<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\TemplateMiner;
use Watcher\Source\LineParser;
use Watcher\Source\LogEvent;

final class TemplateMinerTest extends TestCase
{
    private function ev(string $msg, Level $l = Level::Error, ?string $type = null, string $frame = 'a.php:1'): LogEvent
    {
        return new LogEvent(new DateTimeImmutable('2026-01-01'), $l, $msg, $type, [$frame], [], []);
    }

    /**
     * @param list<LogEvent> $events
     * @return list<?string> distinct templates
     */
    private function mine(array $events, int $min = 3): array
    {
        $n = new Normalizer();
        $m = new TemplateMiner($min);
        foreach ($events as $e) {
            $m->add($e, TemplateMiner::tokens($n, $e));
        }

        return array_values(array_unique(array_map(
            fn (LogEvent $e) => $m->template($m->keyOf($e, TemplateMiner::tokens($n, $e))),
            $events,
        ), SORT_REGULAR));
    }

    public function testVaryingPositionBecomesWildcard(): void
    {
        $names = ['Anna Kowalski', 'Bob Marley', 'Eve Adams'];
        $events = array_map(fn (string $n) => $this->ev('Failed to send welcome mail to ' . $n), $names);

        self::assertSame(['Failed to send welcome mail to <*> <*>'], $this->mine($events));
    }

    public function testNumbersAlreadyMaskedAndKept(): void
    {
        $events = [$this->ev('took 1 ms'), $this->ev('took 22 ms'), $this->ev('took 333 ms')];

        self::assertSame(['took <N> ms'], $this->mine($events));
    }

    public function testLowVolumeWithheld(): void
    {
        self::assertSame([null], $this->mine([$this->ev('hello Anna'), $this->ev('hello Bob')]));
    }

    public function testClustersByLevelTypeTokenCountFrame(): void
    {
        $events = [
            $this->ev('x y z'), $this->ev('x y z'), $this->ev('x y z'),
            $this->ev('x y z', Level::Critical),
            $this->ev('x y z', type: 'T'),
            $this->ev('x y z', frame: 'b.php:2'),
            $this->ev('x y z w'),
        ];

        self::assertSame(['x y z', null], $this->mine($events));
    }

    public function testOrderIndependent(): void
    {
        $events = [$this->ev('a Anna b'), $this->ev('a Bob b'), $this->ev('a Eve b'), $this->ev('a Eve b')];

        self::assertSame($this->mine($events), $this->mine(array_reverse($events)));
    }

    public function testPrivacyCorpusTemplatesHoldNoSeededValue(): void
    {
        $parser = new LineParser();
        $events = [];
        foreach ((array) file(__DIR__ . '/../../Fixtures/privacy/corpus.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $events[] = $parser->parse((string) $line);
        }
        /** @var list<string> $seeded */
        $seeded = require __DIR__ . '/../../Fixtures/privacy/seeded-values.php';

        $templates = array_filter($this->mine($events));
        self::assertNotEmpty($templates);
        self::assertContains('[APP]: Failed to send welcome mail to <EMAIL> <*> <*> <*> <*> <*> <*> <*> from <IP>', $templates);
        foreach ($templates as $t) {
            foreach ($seeded as $v) {
                self::assertStringNotContainsString($v, $t);
            }
        }
    }
}
