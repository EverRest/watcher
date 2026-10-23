<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Source;

use PHPUnit\Framework\TestCase;
use Watcher\Source\EventAssembler;

final class EventAssemblerTest extends TestCase
{
    /** @return list<string> */
    public static function fixtureLines(string $name): array
    {
        return array_values(array_filter(
            explode("\n", (string) file_get_contents(__DIR__ . '/../../Fixtures/logs/' . $name)),
            static fn (string $l, int $i): bool => $l !== '' || $i === 0,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * @param iterable<string> $lines
     *
     * @return list<string>
     */
    private function assemble(iterable $lines): array
    {
        return iterator_to_array((new EventAssembler())->assemble($lines), false);
    }

    public function testStacktraceContinuationLinesJoinTheirRecord(): void
    {
        $records = $this->assemble(self::fixtureLines('laravel_multiline.log'));

        self::assertCount(2, $records);
        self::assertStringStartsWith('[2026-10-22 10:00:00] production.ERROR: Job failed', $records[0]);
        self::assertStringContainsString("\n[stacktrace]\n#0 /var/www/html/app/Services/X.php(42)", $records[0]);
        self::assertStringEndsWith("#1 {main}\n\"} []", $records[0]);
        self::assertStringStartsWith('[2026-10-22 10:00:05]', $records[1]);
    }

    public function testJsonObjectLineStartsARecordAndIsNotSwallowed(): void
    {
        $records = $this->assemble(self::fixtureLines('mixed_json.log'));

        self::assertCount(3, $records);
        self::assertSame("[2026-10-22 10:05:00] production.ERROR: First failure\n#0 /var/www/html/app/A.php(1): A->a()", $records[0]);
        self::assertStringStartsWith('{"level":"error"', $records[1]);
        self::assertStringEndsWith("O->o()", $records[1]);
        self::assertSame('[2026-10-22 10:05:02] production.ERROR: Last failure', $records[2]);
    }

    public function testLeadingOrphanLineBecomesItsOwnRecord(): void
    {
        self::assertSame(["#0 orphan\n#1 {main}"], $this->assemble(['#0 orphan', '#1 {main}']));
    }

    public function testBracketLineWithoutATimestampIsAContinuation(): void
    {
        self::assertSame(["[2026-10-22 10:00:00] x.ERROR: a\n[stacktrace]"], $this->assemble(['[2026-10-22 10:00:00] x.ERROR: a', '[stacktrace]']));
    }

    public function testIsLazy(): void
    {
        $pulled = 0;
        $lines = (function () use (&$pulled) {
            foreach (['[2026-10-22 10:00:00] x.ERROR: a', 'c', '[2026-10-22 10:00:01] x.ERROR: b', '[2026-10-22 10:00:02] x.ERROR: c'] as $l) {
                ++$pulled;
                yield $l;
            }
        })();

        $gen = (new EventAssembler())->assemble($lines);
        $gen->current();

        self::assertSame(3, $pulled);
    }

    public function testEmptyStream(): void
    {
        self::assertSame([], $this->assemble([]));
    }
}
