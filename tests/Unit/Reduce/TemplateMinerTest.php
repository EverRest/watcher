<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\Fingerprinter;
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
     * @param  list<LogEvent> $events
     * @return list<?string> the template of each event's fingerprint, in event order
     */
    private function perEvent(array $events, int $min = 3, bool $similarity = true): array
    {
        $n = new Normalizer();
        $f = new Fingerprinter($n);
        $m = new TemplateMiner($min, enabled: $similarity);
        foreach ($events as $e) {
            $m->add($e, TemplateMiner::tokens($n, $e), $f->of($e));
        }

        return array_map(static fn (LogEvent $e) => $m->template($f->of($e)), $events);
    }

    /**
     * @param list<LogEvent> $events
     * @return list<?string> distinct templates
     */
    private function mine(array $events, int $min = 3, bool $similarity = true): array
    {
        return array_values(array_unique($this->perEvent($events, $min, $similarity), SORT_REGULAR));
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

    public function testSameLengthUnrelatedMessagesStaySeparate(): void
    {
        $events = [];
        foreach (['Anna', 'Bob', 'Eve'] as $n) {
            $events[] = $this->ev('Disk quota exceeded for ' . $n);
        }
        foreach (['Visa', 'Amex', 'Mada'] as $c) {
            $events[] = $this->ev('Card payment rejected by ' . $c);
        }

        self::assertSame(['Disk quota exceeded for <*>', 'Card payment rejected by <*>'], $this->mine($events));
        // the old behaviour (similarity off) merged them into one blank template
        self::assertSame(['<*> <*> <*> <*> <*>'], $this->mine($events, similarity: false));
    }

    public function testSimilarityOffKeepsTheExactTokenCountBehaviour(): void
    {
        $events = [$this->ev('Failed to send mail to Anna'), $this->ev('Failed to send mail to Bob'), $this->ev('Failed to send mail to Eve')];

        self::assertSame(['Failed to send mail to <*>'], $this->mine($events, similarity: false));
    }

    public function testOrderIndependentOverFiftyShuffles(): void
    {
        $events = [];
        foreach (['Anna', 'Bob', 'Eve', 'Tom'] as $i => $n) {
            $events[] = $this->ev('Disk quota exceeded for ' . $n);
            $events[] = $this->ev('Disk quota exceeded for ' . $n);
            $events[] = $this->ev('Card payment rejected by ' . $n);
            $events[] = $this->ev('Disk space is low on ' . $n . ' now');
            $events[] = $this->ev('Disk space is low on node' . $i . ' now');
        }
        $expected = $this->perEvent($events);
        $byMessage = [];
        mt_srand(42);
        for ($i = 0; $i < 50; ++$i) {
            $shuffled = $events;
            shuffle($shuffled);
            $got = $this->perEvent($shuffled);
            foreach ($shuffled as $k => $e) {
                $byMessage[$e->message][] = $got[$k];
            }
        }
        foreach ($events as $k => $e) {
            self::assertSame([$expected[$k]], array_values(array_unique($byMessage[$e->message], SORT_REGULAR)), $e->message);
        }
    }

    public function testSpacelessMessageNeverFails(): void
    {
        $events = [$this->ev('数据库连接失败'), $this->ev('数据库连接失败'), $this->ev('数据库连接失败'), $this->ev('支付网关超时错误')];

        self::assertSame(['数据库连接失败', null], $this->mine($events));
    }

    public function testPlaceholderEqualityDoesNotMergeOppositeMessages(): void
    {
        $m = new TemplateMiner(3);
        $e = $this->ev('ignored');
        for ($i = 0; $i < 3; ++$i) {
            $m->add($e, ['<ID>', '<N>', 'failed'], 'fp-failed');
            $m->add($e, ['<ID>', '<N>', 'succeeded'], 'fp-ok');
        }

        self::assertSame('<ID> <N> failed', $m->template('fp-failed'));
        self::assertSame('<ID> <N> succeeded', $m->template('fp-ok'));
    }

    public function testFingerprintWithTwoClustersTakesLargest(): void
    {
        $m = new TemplateMiner(2);
        $e = $this->ev('ignored');
        for ($i = 0; $i < 4; ++$i) {
            $m->add($e, ['alpha', 'beta', 'gamma', 'delta'], 'fp');
        }
        for ($i = 0; $i < 3; ++$i) {
            $m->add($e, ['one', 'two', 'three', 'four'], 'fp');
        }

        self::assertSame('alpha beta gamma delta', $m->template('fp'));
        self::assertNull($m->template('unknown'));
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
        // spec 004 US3: same-length unrelated groups keep their own text, a name in a 2-event retry loop is withheld,
        // and an identical value repeating >= min events is only safe because the Normalizer already masked it.
        self::assertContains('[SIM]: Archive request queued for <*> <*> today', $templates);
        self::assertContains('[SIM]: Thumbnail render stalled for <*> <*> today', $templates);
        self::assertContains('[SIM]: Mailbox full for <EMAIL> quota exceeded', $templates);
        foreach ($templates as $t) {
            self::assertStringNotContainsString('Vasyl', $t);
            foreach ($seeded as $v) {
                self::assertStringNotContainsString($v, $t);
            }
        }
    }
}
