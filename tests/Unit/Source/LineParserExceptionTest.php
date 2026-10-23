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
}
