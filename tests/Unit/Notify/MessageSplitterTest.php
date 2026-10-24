<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use PHPUnit\Framework\TestCase;
use Watcher\Notify\MessageSplitter;

final class MessageSplitterTest extends TestCase
{
    private const MARKER = '… (cut)';

    /** A section whose "render" is its source capped at $n characters. */
    private static function section(string $text): \Closure
    {
        return static fn (int $n): string => mb_substr($text, 0, $n);
    }

    /**
     * @param list<string> $texts
     * @return list<\Closure>
     */
    private static function sections(array $texts): array
    {
        return array_map(self::section(...), $texts);
    }

    public function testSinglePartHasNoPrefix(): void
    {
        self::assertSame(["a\n\nb"], MessageSplitter::split(self::sections(['a', 'b']), 100, self::MARKER));
    }

    public function testSingleSectionBetweenLimitAndReserveStaysOnePart(): void
    {
        $text = str_repeat('x', 95);

        self::assertSame([$text], MessageSplitter::split(self::sections([$text]), 100, self::MARKER));
    }

    public function testEmptySectionListGivesNoParts(): void
    {
        self::assertSame([], MessageSplitter::split([], 100, self::MARKER));
    }

    /** @return iterable<string, array{int}> */
    public static function counts(): iterable
    {
        yield '1' => [1];
        yield '5' => [5];
        yield '40' => [40];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('counts')]
    public function testPartsFitAndNumbered(int $count): void
    {
        $texts = [];
        for ($i = 0; $i < $count; ++$i) {
            $texts[] = sprintf('S%02d ', $i) . str_repeat('é', 40 + $i * 3);
        }

        $parts = MessageSplitter::split(self::sections($texts), 1000, self::MARKER);

        $m = count($parts);
        self::assertLessThanOrEqual(10, $m);
        foreach ($parts as $i => $part) {
            self::assertLessThanOrEqual(1000, mb_strlen($part));
            if ($m > 1) {
                self::assertStringStartsWith(sprintf('(%d/%d) ', $i + 1, $m), $part);
            }
        }
        $joined = implode("\n\n", $parts);
        foreach ($texts as $t) {
            self::assertSame(1, substr_count($joined, $t), 'each section exactly once, never split');
        }
    }

    public function testNoSectionIsSplitAcrossPartsAndOrderIsKept(): void
    {
        $parts = MessageSplitter::split(self::sections(['HEADER', str_repeat('a', 60), str_repeat('b', 60), 'FOOTER']), 100, self::MARKER);

        self::assertStringStartsWith('(1/', $parts[0]);
        self::assertStringContainsString('HEADER', $parts[0]);
        self::assertStringContainsString('FOOTER', $parts[count($parts) - 1]);
        self::assertSame(count($parts), (int) substr(explode('/', $parts[0])[1], 0, strpos(explode('/', $parts[0])[1], ')') ?: 1));
    }

    public function testExampleOf1500x3(): void
    {
        $s = [str_repeat('a', 1500), str_repeat('b', 1500), str_repeat('c', 1500)];

        $parts = MessageSplitter::split(self::sections($s), 4000, self::MARKER);

        self::assertSame(['(1/2) ' . $s[0] . "\n\n" . $s[1], '(2/2) ' . $s[2]], $parts);
    }

    public function testOverlongSectionCutWithMarker(): void
    {
        $parts = MessageSplitter::split(self::sections([str_repeat('é', 6000)]), 4000, self::MARKER);

        self::assertCount(1, $parts);
        self::assertLessThanOrEqual(4000, mb_strlen($parts[0]));
        self::assertGreaterThan(3900, mb_strlen($parts[0]));
        self::assertStringEndsWith(' ' . self::MARKER, $parts[0]);
        self::assertSame(1, preg_match('//u', $parts[0]), 'valid UTF-8, cut on a code point');
    }

    public function testCutReRendersTheSourceSoMarkupStaysBalanced(): void
    {
        $render = static fn (int $n): string => '<b>' . mb_substr(str_repeat('x', 500), 0, $n) . '</b>';

        $parts = MessageSplitter::split([$render], 100, self::MARKER);

        self::assertMatchesRegularExpression('~^<b>x+</b> … \(cut\)$~', $parts[0]);
        self::assertLessThanOrEqual(100, mb_strlen($parts[0]));
    }

    public function testMarkerLongerThanTheLimitStillTerminates(): void
    {
        $parts = MessageSplitter::split(self::sections([str_repeat('x', 200)]), 20, str_repeat('m', 50));

        self::assertNotSame([], $parts);
        foreach ($parts as $p) {
            self::assertLessThanOrEqual(20, mb_strlen($p));
        }
    }

    public function testPartCapAnnouncesRemainder(): void
    {
        $texts = [];
        for ($i = 0; $i < 40; ++$i) {
            $texts[] = sprintf('S%02d ', $i) . str_repeat('x', 90);
        }

        $parts = MessageSplitter::split(self::sections($texts), 120, self::MARKER);

        self::assertCount(10, $parts);
        self::assertStringStartsWith('(10/10) ', $parts[9]);
        self::assertMatchesRegularExpression('~^\(10/10\) … 31 more sections not shown$~', $parts[9]);
        $joined = implode("\n\n", array_slice($parts, 0, 9));
        for ($i = 0; $i < 9; ++$i) {
            self::assertStringContainsString($texts[$i], $joined);
        }
        self::assertStringNotContainsString($texts[9], implode("\n\n", $parts));
    }

    public function testExactlyTenPartsIsNotCapped(): void
    {
        $texts = array_fill(0, 10, str_repeat('x', 100));

        $parts = MessageSplitter::split(self::sections($texts), 120, self::MARKER);

        self::assertCount(10, $parts);
        self::assertStringNotContainsString('not shown', implode('', $parts));
    }

    public function testCountsCharactersNotBytes(): void
    {
        $text = str_repeat('日', 90);

        self::assertCount(1, MessageSplitter::split(self::sections([$text]), 100, self::MARKER));
    }
}
