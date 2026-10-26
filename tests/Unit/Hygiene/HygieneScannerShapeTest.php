<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Hygiene;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Hygiene\HygieneScanner;
use Watcher\Hygiene\HygieneSettings;
use Watcher\Hygiene\ScanResult;
use Watcher\Level;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Source\LogEvent;

final class HygieneScannerShapeTest extends TestCase
{
    private static function scanner(int $maxLine = 100): HygieneScanner
    {
        $n = new Normalizer();

        return new HygieneScanner($n, new Redactor($n), new HygieneSettings(enabled: true, maxLineLength: $maxLine), pathRoots: ['/var/www/html']);
    }

    private static function event(Level $level, string $message, string $file = 'A'): LogEvent
    {
        return new LogEvent(new DateTimeImmutable('2026-10-07T10:00:00+00:00'), $level, $message, null, [], ['file' => "/var/www/html/app/$file.php", 'line' => 1], []);
    }

    /** @return array<string, int> habit counter => total over all sites */
    private static function totals(ScanResult $r): array
    {
        $t = ['long' => 0, 'trace' => 0, 'mismatch' => 0];
        foreach ($r->sites as $s) {
            $t['long'] += $s->long;
            $t['trace'] += $s->trace;
            $t['mismatch'] += $s->mismatch;
        }

        return $t;
    }

    public function testACleanStreamHasNoShapeFindings(): void
    {
        $s = self::scanner();
        foreach (range(1, 5) as $i) {
            $s->observe(self::event(Level::Info, 'Order created'));
        }

        $r = $s->result();
        self::assertSame(['long' => 0, 'trace' => 0, 'mismatch' => 0], self::totals($r));
        self::assertSame(['text' => 5], $r->formats);
        self::assertSame(0, $r->maxLength > 100 ? 1 : 0);
    }

    public function testALineBreakStillMarksATraceAfterTheMessageIsCleaned(): void
    {
        $s = self::scanner();
        $cleaned = $s->observe(self::event(Level::Error, "Unhandled failure\n    at Foo.bar (x)"));

        self::assertSame(1, self::totals($s->result())['trace'], 'the shape is judged on the line as it was written');
        self::assertStringNotContainsString("\n", $cleaned->message, 'what the rest of the pass sees is the cleaned text');
    }

    public function testALineOverTheLimitIsLongAndTheLongestIsRemembered(): void
    {
        $s = self::scanner(100);
        $s->observe(self::event(Level::Info, str_repeat('x', 100)));
        $s->observe(self::event(Level::Info, str_repeat('y', 101)));
        $s->observe(self::event(Level::Info, str_repeat('y', 250), 'B'));

        $r = $s->result();
        self::assertSame(2, self::totals($r)['long']);
        self::assertSame(250, $r->maxLength);
    }

    /** @return iterable<string, array{string}> */
    public static function traces(): iterable
    {
        yield 'newline' => ["failed\n#0 /app/X.php(3): run()"];
        yield 'php' => ['Boom Stack trace: #0 /app/X.php(3)'];
        yield 'frame list' => ['error #0 /app/X.php(12): Foo->bar()'];
        yield 'java' => ['oops at com.acme.Foo.run(Foo.java:42)'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('traces')]
    public function testATraceInTheMessageIsCounted(string $message): void
    {
        $s = self::scanner();
        $s->observe(self::event(Level::Error, $message));
        $s->observe(self::event(Level::Error, 'plain failure'));

        self::assertSame(1, self::totals($s->result())['trace']);
    }

    public function testFormatsAreClassedAndAnAccessLineAtWarningOrAboveIsAMismatch(): void
    {
        $s = self::scanner();
        $access = '10.0.0.1 - - [07/Oct/2026:10:00:00 +0000] "GET /health HTTP/1.1" 200 12';
        $s->observe(self::event(Level::Info, $access, 'N'));
        $s->observe(self::event(Level::Error, $access, 'N'));
        $s->observe(self::event(Level::Warning, $access, 'N'));
        $s->observe(self::event(Level::Info, '{"audit":true}', 'J'));
        $s->observe(self::event(Level::Info, 'hello', 'T'));

        $r = $s->result();
        self::assertSame(['access' => 3, 'json' => 1, 'text' => 1], $r->formats);
        self::assertSame(2, self::totals($r)['mismatch']);
    }

    public function testShapeCountersHoldNumbersOnly(): void
    {
        $s = self::scanner(10);
        $s->observe(self::event(Level::Error, "secret line anna@example.org\n#0 /a(1): f()"));

        $dump = json_encode($s->result()->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret', $dump);
        self::assertStringNotContainsString('anna@', $dump);
    }
}
