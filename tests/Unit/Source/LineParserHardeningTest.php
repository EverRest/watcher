<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source;

use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Source\LineParser;

/** F-01 / F-03 / A4-09: hostile lines are bounded in time and in memory, and a failed regex is visible. */
final class LineParserHardeningTest extends TestCase
{
    private const HEAD = '[2026-10-24 10:00:00] app.ERROR: login failed for x';

    protected function tearDown(): void
    {
        ini_set('pcre.jit', '1');
        ini_set('pcre.backtrack_limit', '1000000');
    }

    /** @return list<string> */
    private static function adversarial(): array
    {
        return [
            self::HEAD . str_repeat(' {', 65536),
            self::HEAD . str_repeat(' [', 65536),
            self::HEAD . ' {' . str_repeat('} {', 43000),
            self::HEAD . ' {"a":1}' . str_repeat(' [{', 43000),
            str_repeat('[2026-10-24 10:00:00] a.ERROR: ', 4000),
            str_repeat('a', 131072),
        ];
    }

    public function testNoHostileLineTakesMoreThanASecondWhateverTheJitSetting(): void
    {
        foreach ([1, 0] as $jit) {
            ini_set('pcre.jit', (string) $jit);
            foreach (self::adversarial() as $i => $line) {
                $p = new LineParser();
                $t = microtime(true);
                $p->parse($line);
                $p->isOrphanTrace($line);
                $p->continues($line, self::HEAD . ' {}');
                $p->continues('x', $line);
                self::assertLessThan(1.0, microtime(true) - $t, "case $i jit=$jit");
            }
        }
    }

    public function testALongLineIsCutAndCounted(): void
    {
        $p = new LineParser();
        $e = $p->parse(self::HEAD . str_repeat('é', 20000));

        self::assertLessThanOrEqual(LineParser::MAX_LINE, strlen($e->message));
        self::assertSame(1, preg_match('//u', $e->message));
        self::assertSame(1, $p->diagnostics()['truncated_lines'] ?? 0);
        self::assertArrayNotHasKey('truncated_lines', (new LineParser())->diagnostics());
    }

    public function testAnOrdinaryLineAndALargeJsonEventAreUntouched(): void
    {
        $p = new LineParser();
        $json = (string) json_encode(['message' => 'boom', 'level' => 'error', 'blob' => str_repeat('x', 30000)]);
        $e = $p->parse($json);

        self::assertSame('boom', $e->message);
        self::assertSame(Level::Error, $e->level);
        self::assertArrayNotHasKey('truncated_lines', $p->diagnostics());
    }

    public function testOnlyContinuationShapedLinesContinueARecord(): void
    {
        $p = new LineParser();
        $json = '{"message":"m","level":"error"}';
        $monolog = '[2026-10-22 10:00:00] production.ERROR: Job failed {"a":1} []';

        foreach ([$json, $monolog] as $open) {
            self::assertFalse($p->continues('Payment gateway unreachable', $open), $open);
            foreach (['    at Foo.bar', '#3 /x.php(1): f()', 'Caused by: x', '  }', '[stacktrace]', ''] as $tail) {
                self::assertTrue($p->continues($tail, $open), "$open + $tail");
            }
        }
    }

    public function testARecordIsBoundedInLinesAndBytes(): void
    {
        $p = new LineParser();
        $open = self::HEAD . str_repeat("\n    at f()", LineParser::MAX_RECORD_LINES);
        self::assertFalse($p->continues('    at g()', $open));

        $big = self::HEAD . "\n" . str_repeat('    at ' . str_repeat('x', 8000) . "\n", 9);
        self::assertFalse($p->continues('    at g()', $big));
        self::assertTrue($p->continues('    at g()', self::HEAD . "\n    at f()"));
    }

    public function testAStreamLevelLabelStopsTraceModeAbsorption(): void
    {
        $p = new LineParser();
        $open = "panic: boom\ngoroutine 1 [running]:";

        self::assertTrue($p->continues('something odd', $open));
        self::assertFalse($p->continues('something odd', $open, true));
        self::assertTrue($p->continues("\tmain.main()", $open, true));
    }

    public function testAFailedRegexKeepsTheLineAsAWarningAndIsCounted(): void
    {
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '50');
        $p = new LineParser();
        $e = $p->parse(self::HEAD . str_repeat(' {x', 300));

        self::assertSame(Level::Warning, $e->level);
        self::assertNotSame('', $e->message);
        self::assertTrue($p->lastParseFailed);
        self::assertGreaterThan(0, $p->diagnostics()['regex_failures'] ?? 0);

        ini_set('pcre.backtrack_limit', '1000000');
        $p->parse('plain fine line');
        self::assertFalse($p->lastParseFailed);
    }

    public function testACutNeverLeavesAPartialTokenAtTheEnd(): void
    {
        $p = new LineParser();
        foreach (['sk-ant-api03-abcdefghij', 'password=hunterxyz', 'ghp_abcdefghijklmnop'] as $secret) {
            for ($straddle = 2; $straddle <= 6; ++$straddle) {
                $line = str_repeat('x ', intdiv(LineParser::MAX_LINE - $straddle, 2)) . $secret . ' tail';
                $cut = $p->capLine($line);

                self::assertLessThanOrEqual(LineParser::MAX_LINE, strlen($cut));
                self::assertStringEndsWith(' ', $cut);
                self::assertStringNotContainsString(substr($secret, 0, 2), $cut);
            }
        }
        self::assertSame('', $p->capLine(str_repeat('a', 20000)));
    }

    public function testAMonologRecordCutInsideItsContextKeepsOnlyTheMessage(): void
    {
        $p = new LineParser();
        $line = '[2026-10-22 10:00:00] app.ERROR: boom now {"email":"early.leak@example.org","pad":"' . str_repeat('p', 9000) . '","t":"late.leak@example.org"} []';
        $e = $p->parse($line);

        self::assertSame('boom now', $e->message);
        self::assertSame([], $e->fields);
        self::assertSame(Level::Error, $e->level);
        self::assertSame(1, $p->diagnostics()['truncated_lines'] ?? 0);
    }

    public function testAnOversizedJsonLineBecomesAPlaceholderWithItsLevel(): void
    {
        $p = new LineParser();
        $line = '{"level":"error","message":"early.leak@example.org","pad":"' . str_repeat('p', 300000) . '","t":"late.leak@example.org"}';
        $e = $p->parse($line);

        self::assertSame(Level::Error, $e->level);
        self::assertStringNotContainsString('leak', $e->message);
        self::assertSame([], $e->fields);
        self::assertSame(1, $p->diagnostics()['truncated_structured'] ?? 0);
        self::assertArrayNotHasKey('truncated_structured', (new LineParser())->diagnostics());

        $unknown = $p->parse('{"pad":"' . str_repeat('p', 300000) . '"}');
        self::assertSame(Level::Warning, $unknown->level);
    }

    public function testAJsonLineOverTheTextCapStillParsesAsJson(): void
    {
        $p = new LineParser();
        $e = $p->parse((string) json_encode(['level' => 'error', 'message' => 'real message', 'blob' => str_repeat('x', 20000)]));

        self::assertSame('real message', $e->message);
        self::assertSame(Level::Error, $e->level);
    }

    public function testAMonologListContextCutAtTheHeadKeepsOnlyTheMessage(): void
    {
        $p = new LineParser();
        $line = '[2026-10-22 10:00:00] app.ERROR: boom now ["early.leak@example.org","' . str_repeat('p', 9000) . '","late.leak@example.org"] []';
        $e = $p->parse($line);

        self::assertSame('boom now', $e->message);
        self::assertSame([], $e->fields);
        self::assertSame(Level::Error, $e->level);
    }

    public function testAnOversizedStructuredLineAlwaysStartsItsOwnRecord(): void
    {
        $p = new LineParser();
        $open = "[2026-10-22 10:00:00] app.ERROR: boom {}\n    at f()";
        $json = '{"level":"error","message":"m","pad":"' . str_repeat('p', 300000) . '"}';

        self::assertFalse($p->continues($json, $open));
        self::assertFalse($p->continues("  \t" . $json, $open));
        self::assertFalse($p->isOrphanTrace($json));
    }

    public function testALineThatIsEmptyOnceCutNeverContinuesARecord(): void
    {
        $p = new LineParser();
        $open = '[2026-10-22 10:00:00] app.ERROR: boom {}';

        self::assertSame('', $p->capLine(str_repeat('x', 20000)));
        self::assertFalse($p->continues(str_repeat('x', 20000), $open));
        self::assertFalse($p->continues(str_repeat('x', 20000), "panic: boom\ngoroutine 1 [running]:"));
        self::assertTrue($p->continues('', $open), 'a genuinely empty line still does');
    }

    public function testTheRecordByteBoundMeasuresTheRawLine(): void
    {
        $p = new LineParser();
        $open = '[2026-10-22 10:00:00] app.ERROR: boom {}';

        self::assertFalse($p->continues('    at ' . str_repeat('x', 200000), $open));
        self::assertTrue($p->continues('    at ' . str_repeat('x', 100), $open));
    }
}
