<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source;

use PHPUnit\Framework\TestCase;
use Watcher\Reduce\Fingerprinter;
use Watcher\Reduce\Normalizer;
use Watcher\Source\EventAssembler;
use Watcher\Source\LineParser;
use Watcher\Source\LogEvent;

final class LineParserExceptionTest extends TestCase
{
    /** @return list<LogEvent> */
    private function events(string $fixture): array
    {
        $parser = new LineParser();
        $out = [];
        foreach ((new EventAssembler())->assemble(EventAssemblerTest::fixtureLines($fixture)) as $record) {
            $out[] = $parser->parse($record);
        }

        return $out;
    }

    public function testMultilineRecordYieldsOneEventWithTypeAndFrames(): void
    {
        $events = $this->events('laravel_multiline.log');

        self::assertCount(2, $events);
        self::assertSame('App\Exceptions\QuotaException', $events[0]->errorType);
        self::assertSame([
            '/var/www/html/app/Services/Quota.php:42',
            '/var/www/html/app/Services/X.php:42',
            '/var/www/html/vendor/laravel/framework/src/Illuminate/Queue/Worker.php:120',
        ], $events[0]->frames);
        self::assertSame('Job failed', $events[0]->message);
        self::assertNull($events[1]->errorType);
    }

    public function testObjectFormInContextGivesTypeAndOriginFrame(): void
    {
        $e = $this->events('query_exception_a.log')[0];

        self::assertSame('Illuminate\Database\QueryException', $e->errorType);
        self::assertSame(['/var/www/html/app/Repositories/QuotaRepository.php:88'], $e->frames);
        self::assertSame('Query failed', $e->message);
        self::assertStringContainsString('SQLSTATE[40001]', (string) $e->fields['exception']); // raw text stays in fields only
    }

    public function testMessageFormGivesTypeAndFrames(): void
    {
        $e = $this->events('message_form.log')[0];

        self::assertSame('RuntimeException', $e->errorType);
        self::assertSame(['/var/www/html/app/Foo.php:12', '/var/www/html/app/Bar.php:7'], $e->frames);
    }

    public function testFramesCappedAtFive(): void
    {
        $e = $this->events('deep_trace.log')[0];

        self::assertNull($e->errorType);
        self::assertCount(5, $e->frames);
        self::assertSame('/var/www/html/app/A.php:1', $e->frames[0]);
        self::assertSame('/var/www/html/app/E.php:5', $e->frames[4]);
    }

    public function testNothingExtractableLeavesTypeAndFramesEmpty(): void
    {
        $e = $this->events('plain.log')[0];

        self::assertNull($e->errorType);
        self::assertSame([], $e->frames);
    }

    public function testExceptionTextAndMessageNeverEnterTypeOrFrames(): void
    {
        $e = $this->events('query_exception_a.log')[0];
        $all = $e->errorType . ' ' . implode(' ', $e->frames);

        self::assertStringNotContainsString('SQLSTATE', $all);
        self::assertStringNotContainsString('Deadlock', $all);
        self::assertStringNotContainsString('update', $all);
    }

    public function testFrameArgumentsNeverReachFrames(): void
    {
        $e = $this->events('mailer_args.log')[0];

        self::assertSame(['/var/www/x.php:5'], $e->frames);
        $dump = json_encode([$e->errorType, $e->frames, $e->message]);
        self::assertStringNotContainsString('anna@example.com', (string) $dump);
        self::assertStringNotContainsString('tok_ABC', (string) $dump);
    }

    public function testSameClassAndTopFrameWithDifferentSqlValuesShareAFingerprint(): void
    {
        $fp = new Fingerprinter(new Normalizer());
        $a = $this->events('query_exception_a.log')[0];
        $b = $this->events('query_exception_b.log')[0];

        self::assertNotSame($a->fields['exception'], $b->fields['exception']);
        self::assertSame($fp->of($a), $fp->of($b));
    }

    public function testContinuationLinesNeverEndUpInTheMessage(): void
    {
        // The context JSON starts on the SECOND physical line; the stack line before it carries call arguments.
        $record = "[2026-10-07T21:49:49+00:00] production.ERROR: Mail failed\n"
            . "#0 /var/www/html/app/Mailer.php(5): App\\Mailer->send('Anna Kowalski', 'anna@example.com') "
            . '{"exception":"[object] (RuntimeException(code: 0): m at /var/www/html/app/Mailer.php:5)"} []';

        $e = (new LineParser())->parse($record);

        self::assertSame('Mail failed', $e->message);
        self::assertStringNotContainsString('Anna Kowalski', $e->message);
        self::assertStringNotContainsString('anna@example.com', $e->message);
    }

    public function testFramePathsMustLookLikeSourceFiles(): void
    {
        $line = '[2026-10-07T21:49:49+00:00] production.ERROR: x '
            . '{"exception":"[object] (RuntimeException(code: 0): m at /home/anna.kowalski/secret:9)"} []';
        $e = (new LineParser())->parse($line);
        self::assertSame([], $e->frames, 'a path without a file extension is not a frame');

        $ok = (new LineParser())->parse('RuntimeException: Boom in /var/www/html/app/Foo.php:12');
        self::assertSame('RuntimeException', $ok->errorType);
        self::assertSame(['/var/www/html/app/Foo.php:12'], $ok->frames);
    }

    public function testJsonTypeThatIsFreeTextIsDropped(): void
    {
        $p = new LineParser();
        $e = $p->parse('{"level":"error","message":"card declined","error":{"kind":"declined for Olena Shevchenko"}}');
        self::assertNull($e->errorType);

        $e = $p->parse('{"level":"error","message":"x","exception.class":"App\\\\Http2Exception"}');
        self::assertSame('App\\Http2Exception', $e->errorType);

        $e = $p->parse('{"level":"error","message":"x","exception.class":"' . str_repeat('A', 121) . '"}');
        self::assertNull($e->errorType);
    }

    public function testMessageFormTypeMustBeAClassName(): void
    {
        $line = '[2026-10-07T10:00:00+00:00] production.ERROR: Http2Exception: lookup failed in /var/www/html/app/X.php:12 [] []';
        $e = (new LineParser())->parse($line);
        self::assertSame('Http2Exception', $e->errorType);

        $e = (new LineParser())->parse('[2026-10-07T10:00:00+00:00] production.ERROR: ' . str_repeat('A', 121) . 'Exception: x in /var/www/html/app/X.php:12 [] []');
        self::assertNull($e->errorType);
    }
}
