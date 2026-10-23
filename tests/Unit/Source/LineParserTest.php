<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Source\LineParser;
use Watcher\Source\LogEvent;

final class LineParserTest extends TestCase
{
    private function parser(): LineParser
    {
        return new LineParser();
    }

    public function testTextLineWithContextAndExtra(): void
    {
        $e = $this->parser()->parse('[2026-10-07T21:49:49+02:00] prod.ERROR [APP]: Payment failed for 42 {"ctx":1,"who":"a","n":{"k":true}} []');

        self::assertSame(Level::Error, $e->level);
        self::assertSame('Payment failed for 42', $e->message);
        self::assertSame('2026-10-07T19:49:49+00:00', $e->time->format('c'));
        self::assertSame(['ctx' => 1, 'who' => 'a', 'n.k' => true], $e->fields);
        self::assertSame([], $e->labels);
        self::assertNull($e->errorType);
    }

    public function testTextLineWithoutContext(): void
    {
        $e = $this->parser()->parse('[2026-10-07T21:49:49+00:00] x.WARNING [APP]: disk almost full');
        self::assertSame(Level::Warning, $e->level);
        self::assertSame('disk almost full', $e->message);
        self::assertSame([], $e->fields);
    }

    public function testTextLineWithBadContextJsonKeepsMessage(): void
    {
        $e = $this->parser()->parse('[2026-10-07T21:49:49+00:00] x.ERROR [APP]: boom {not json}');
        self::assertSame(Level::Error, $e->level);
        self::assertSame('boom', $e->message);
        self::assertSame([], $e->fields);
    }

    public function testJsonLine(): void
    {
        $e = $this->parser()->parse(json_encode([
            'time' => '2026-10-07T10:00:00Z',
            'severity' => 'CRITICAL',
            'msg' => 'db down',
            'exception' => ['class' => 'PDOException', 'trace' => "#0 a.php(1): f()\n#1 b.php(2): g()"],
            'user_id' => 7,
            'meta' => ['region' => 'eu'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(Level::Critical, $e->level);
        self::assertSame('db down', $e->message);
        self::assertSame('PDOException', $e->errorType);
        self::assertSame(['#0 a.php(1): f()', '#1 b.php(2): g()'], $e->frames);
        self::assertSame(['user_id' => 7, 'meta.region' => 'eu'], $e->fields);
        self::assertSame('2026-10-07T10:00:00+00:00', $e->time->format('c'));
    }

    public function testJsonDottedKeysAndAlternatives(): void
    {
        $e = $this->parser()->parse('{"status":"error","message":"m","error.kind":"Timeout","error.stack":["a","b","c","d","e","f","g"]}');
        self::assertSame(Level::Error, $e->level);
        self::assertSame('Timeout', $e->errorType);
        self::assertSame(['a', 'b', 'c', 'd', 'e'], $e->frames);
        self::assertSame([], $e->fields);
    }

    public function testJsonLevelBeatsSeverityOrder(): void
    {
        $e = $this->parser()->parse('{"level":"warning","severity":"error","message":"m"}');
        self::assertSame(Level::Warning, $e->level);
    }

    public function testUnparseableLineIsInfoWithRawMessage(): void
    {
        $e = $this->parser()->parse('just some noise');
        self::assertSame(Level::Info, $e->level);
        self::assertSame('just some noise', $e->message);
    }

    public function testBrokenJsonFallsBackToRaw(): void
    {
        $e = $this->parser()->parse('{"level": "error", oops');
        self::assertSame(Level::Info, $e->level);
        self::assertSame('{"level": "error", oops', $e->message);
    }

    public function testFallbackTimeIsUsedWhenLineHasNone(): void
    {
        $t = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        self::assertEquals($t, $this->parser()->parse('noise', $t)->time);
        self::assertEquals($t, $this->parser()->parse('{"message":"m"}', $t)->time);
    }

    public function testLaravelDefaultFormatWithoutApp(): void
    {
        $e = $this->parser()->parse('[2026-10-07T21:49:49+02:00] production.ERROR: Lock wait {"a":1,"b":{"c":"x"}} []');

        self::assertSame(Level::Error, $e->level);
        self::assertSame('Lock wait', $e->message);
        self::assertSame('2026-10-07T19:49:49+00:00', $e->time->format('c'));
        self::assertSame(['a' => 1, 'b.c' => 'x'], $e->fields);
    }

    public function testMixedStreamWithBothTextFormats(): void
    {
        $lines = [
            '[2026-10-07T21:49:49+00:00] a.ERROR [APP]: one {"k":1}',
            '[2026-10-07T21:49:50+00:00] a.WARNING: two',
            '{"level":"alert","message":"three"}',
        ];
        $events = array_map(fn (string $l): LogEvent => $this->parser()->parse($l), $lines);
        self::assertSame([Level::Error, Level::Warning, Level::Alert], array_map(fn (LogEvent $e): Level => $e->level, $events));
        self::assertSame(['one', 'two', 'three'], array_map(fn (LogEvent $e): string => $e->message, $events));
        self::assertSame(['k' => 1], $events[0]->fields);
    }

    public function testMixedStreamIsParsedLineByLine(): void
    {
        $lines = [
            '[2026-10-07T21:49:49+00:00] a.ERROR [APP]: one',
            '{"level":"alert","message":"two"}',
            'three',
        ];
        $events = array_map(fn (string $l): LogEvent => $this->parser()->parse($l), $lines);
        self::assertSame([Level::Error, Level::Alert, Level::Info], array_map(fn (LogEvent $e): Level => $e->level, $events));
        self::assertSame(['one', 'two', 'three'], array_map(fn (LogEvent $e): string => $e->message, $events));
    }

    public function testLogEventKeepsAtMostFiveFrames(): void
    {
        $e = new LogEvent(new DateTimeImmutable(), Level::Error, 'm', null, ['1', '2', '3', '4', '5', '6'], [], []);
        self::assertSame(['1', '2', '3', '4', '5'], $e->frames);
    }
}
