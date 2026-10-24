<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\Fingerprinter;
use Watcher\Reduce\Normalizer;
use Watcher\Source\LogEvent;

final class FingerprinterTest extends TestCase
{
    private function ev(string $msg, Level $l = Level::Error, ?string $type = 'E', string $frame = 'a.php:1'): LogEvent
    {
        return new LogEvent(new DateTimeImmutable('2026-01-01'), $l, $msg, $type, [$frame], [], []);
    }

    private function fp(LogEvent $e): string
    {
        return (new Fingerprinter(new Normalizer()))->of($e);
    }

    public function testTwelveHex(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $this->fp($this->ev('boom')));
    }

    public function testNumbersDoNotChangeIt(): void
    {
        self::assertSame($this->fp($this->ev('user=123 failed')), $this->fp($this->ev('user=456 failed')));
    }

    public function testLevelChangesIt(): void
    {
        self::assertNotSame($this->fp($this->ev('boom now')), $this->fp($this->ev('boom now', Level::Critical)));
    }

    public function testErrorTypeAndFrameChangeIt(): void
    {
        $base = $this->fp($this->ev('boom now'));
        self::assertNotSame($base, $this->fp($this->ev('boom now', type: 'Other')));
        self::assertNotSame($base, $this->fp($this->ev('boom now', frame: 'b.php:2')));
    }

    public function testNamesAfterSecondWordDoNotChangeIt(): void
    {
        self::assertSame(
            $this->fp($this->ev('Payment declined for Anna Kowalski')),
            $this->fp($this->ev('Payment declined for Maria Garcia')),
        );
    }

    public function testTokenCountChangesIt(): void
    {
        self::assertNotSame($this->fp($this->ev('Payment declined for Anna')), $this->fp($this->ev('Payment declined for Anna K')));
    }

    public function testIndependentOfVolume(): void
    {
        $f = new Fingerprinter(new Normalizer());
        $one = $f->of($this->ev('Payment declined for Anna Kowalski'));
        foreach (['Anna Kowalski', 'Bob Marley', 'Eve Adams', 'Tom Hanks', 'Ann Lee'] as $n) {
            self::assertSame($one, $f->of($this->ev('Payment declined for ' . $n)));
        }
    }

    /** FR-012 pin: values computed before the similarity grouping existed; a change here resets every comparison. */
    public function testGoldenFingerprints(): void
    {
        self::assertSame('18c37570955d', $this->fp($this->ev('Payment declined for Anna Kowalski')));
        self::assertSame('ddc8fcc19766', $this->fp($this->ev('Lock wait timeout exceeded order=4411', type: null, frame: 'db.php:9')));
        self::assertSame('4cdc728c3b6e', $this->fp($this->ev('Connection refused to <x> now', Level::Critical, 'RuntimeException', 'b.php:2')));
        self::assertSame('c161a677331e', $this->fp($this->ev('boom', Level::Warning, null, '')));
    }

    public function testSameIdAtOneEventAndAtFive(): void
    {
        $f = new Fingerprinter(new Normalizer());
        $ids = [];
        foreach ([1, 5] as $n) {
            $ids[$n] = array_unique(array_map(fn (int $i) => $f->of($this->ev('Payment declined for Anna Kowalski')), range(1, $n)));
        }

        self::assertSame($ids[1], $ids[5]);
        self::assertSame(['18c37570955d'], $ids[5]);
    }
}
