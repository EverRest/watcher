<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Source\LineParser;
use Watcher\Source\LogEvent;
use Watcher\Tests\Fakes\FrozenClock;

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

    public function testStructuredJsonHeadIsDecodedOnceAcrossManyContinuations(): void
    {
        $head = '{"level":"error","message":"boom"}';
        $p = new LineParser();
        for ($i = 0; $i < 50; ++$i) {
            self::assertTrue($p->continues('[stacktrace]', $head));
        }
        self::assertSame(1, $p->jsonDecodeCount);
    }

    public function testTimestampRangeUsesInjectedClock(): void
    {
        $frozen = new FrozenClock(new DateTimeImmutable('2026-10-23T12:00:00+00:00'));
        $p = new LineParser($frozen);
        $fallback = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $inside = $p->parse('{"level":"error","message":"m","time":"2026-10-24T11:00:00Z"}', $fallback);
        $outside = $p->parse('{"level":"error","message":"m","time":"2026-10-25T12:00:00Z"}', $fallback);

        self::assertSame('2026-10-24T11:00:00+00:00', $inside->time->format('c'));
        self::assertSame('2026-01-01T00:00:00+00:00', $outside->time->format('c'));
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

    /** @return array<string, array{mixed, ?string}> */
    public static function epochCases(): array
    {
        return [
            'seconds int' => [1792627200, '2026-10-22T00:00:00+00:00'],
            'seconds float' => [1792627200.5, '2026-10-22T00:00:00+00:00'],
            'seconds string' => ['1792627200', '2026-10-22T00:00:00+00:00'],
            'milliseconds int' => [1792627200123, '2026-10-22T00:00:00+00:00'],
            'milliseconds string' => ['1792627200123', '2026-10-22T00:00:00+00:00'],
            'milliseconds float' => [1792627200123.0, '2026-10-22T00:00:00+00:00'],
            'microseconds' => [1792627200123456, '2026-10-22T00:00:00+00:00'],
            'nanoseconds' => [1792627200123456789, '2026-10-22T00:00:00+00:00'],
            'nanoseconds string' => ['1792627200123456789', '2026-10-22T00:00:00+00:00'],
            'iso unchanged' => ['2026-10-22T10:00:00+02:00', '2026-10-22T08:00:00+00:00'],
            'before 2000' => [100000, null],
            'zero' => [0, null],
            'negative' => [-1792627200, null],
            'far future seconds' => [99999999999, null],
            'far future ms' => [99999999999999, null],
            'year 3000 iso' => ['3000-01-01T00:00:00Z', null],
        ];
    }

    #[DataProvider('epochCases')]
    public function testJsonTimeEpochUnitsAndRange(mixed $raw, ?string $expected): void
    {
        $fallback = new DateTimeImmutable('2026-10-23T12:00:00+00:00');
        $e = $this->parser()->parse((string) json_encode(['level' => 'error', 'message' => 'm', 'time' => $raw]), $fallback);

        self::assertSame($expected ?? '2026-10-23T12:00:00+00:00', $e->time->format('c'));
    }

    /** @return array<string, array{string, Level}> */
    public static function levelCases(): array
    {
        return [
            'pino error' => ['{"level":50,"msg":"m"}', Level::Error],
            'pino fatal' => ['{"level":60,"msg":"m"}', Level::Emergency],
            'pino trace' => ['{"level":10,"msg":"m"}', Level::Debug],
            'trace string' => ['{"level":"trace","msg":"m"}', Level::Debug],
            'syslog severity key' => ['{"severity":3,"msg":"m"}', Level::Error],
            'syslog severity 7' => ['{"severity":7,"msg":"m"}', Level::Debug],
            'small int under level is not syslog' => ['{"level":3,"msg":"m"}', Level::Info],
            'http status is not a level' => ['{"status":500,"message":"m"}', Level::Info],
            'http status string is not a level' => ['{"status":"500","message":"m"}', Level::Info],
            'datadog status' => ['{"status":"error","message":"m"}', Level::Error],
            'warning variant' => ['{"level":"Warning","msg":"m"}', Level::Warning],
            'panic' => ['{"level":"panic","msg":"m"}', Level::Emergency],
        ];
    }

    #[DataProvider('levelCases')]
    public function testJsonLevels(string $line, Level $expected): void
    {
        self::assertSame($expected, $this->parser()->parse($line)->level);
    }

    public function testHttpStatusStaysAFieldAndLevelFallsBackToTheOtherKey(): void
    {
        $e = $this->parser()->parse('{"status":500,"level":"warn","message":"m"}');

        self::assertSame(Level::Warning, $e->level);
        self::assertSame(500, $e->fields['status']);
    }

    public function testUnknownLevelTokensAreCounted(): void
    {
        $p = $this->parser();

        $p->parse('{"level":"banana","message":"m"}');
        $p->parse('{"level":"error","message":"m"}');
        $p->parse('{"message":"no level at all"}');
        $p->parse('{"level":7,"message":"m"}');
        $p->parse('[2026-10-22 10:00:00] prod.WEIRD: x');

        self::assertSame(3, $p->unknownLevels);
    }

    /** @return array<string, array{string, Level}> */
    public static function plainCases(): array
    {
        return [
            'upper word' => ['ERROR db down', Level::Error],
            'warn' => ['WARN slow query', Level::Warning],
            'bracketed' => ['[ERROR] queue stuck', Level::Error],
            'logfmt' => ['ts=1 level=warn msg="retry"', Level::Warning],
            'timestamp then level' => ['2026-10-22T10:00:00.000Z CRITICAL disk full', Level::Critical],
            'syslog pri' => ['<3>kernel: oom', Level::Error],
            'syslog pri with facility' => ['<11>app: bad', Level::Error],
            'colon' => ['Fatal error: Uncaught x', Level::Emergency],
            'no level word' => ['just some text', Level::Info],
            'level word not at start' => ['the ERROR was logged', Level::Info],
            'prefix of a longer word' => ['Errors were found', Level::Info],
        ];
    }

    #[DataProvider('plainCases')]
    public function testPlainLineLevelFromPrefix(string $line, Level $expected): void
    {
        $e = $this->parser()->parse($line);

        self::assertSame($expected, $e->level);
        self::assertSame($line, $e->message);
    }

    public function testJsonMessageIsCutToItsFirstPhysicalLineAndTheTailIsCounted(): void
    {
        $p = $this->parser();
        $e = $p->parse('{"level":"error","timestamp":"2026-10-07T21:49:49+00:00","message":"Payment failed\\r\\nargs: anna@example.com\\n  at x"}');

        self::assertSame('Payment failed', $e->message);
        $dropped = [$p->messageTailsDropped];
        self::assertStringNotContainsString("anna", $e->message);

        $p->parse('{"level":"error","message":"one line only"}');
        $dropped[] = $p->messageTailsDropped;
        $p->parse('{"level":"error","message":"trailing break\\n"}');
        $dropped[] = $p->messageTailsDropped;
        self::assertSame([1, 1, 1], $dropped, 'only the cut message counts, not a one-liner or a trailing break');
    }

    public function testJsonMessageTailStillFeedsExceptionClassAndFrames(): void
    {
        $p = $this->parser();
        $e = $p->parse('{"level":"error","message":"App\\\\BoomException: boom in /var/www/app/A.php:5\\n#0 /var/www/app/B.php(9): f(\'anna@example.com\')\\n#1 {main}"}');

        self::assertSame('App\\BoomException', $e->errorType);
        self::assertSame(['/var/www/app/A.php:5', '/var/www/app/B.php:9'], $e->frames);
        self::assertSame('App\\BoomException: boom in /var/www/app/A.php:5', $e->message);
        self::assertSame(1, $p->messageTailsDropped);
    }
}
