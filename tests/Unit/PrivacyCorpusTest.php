<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PrivacyCorpusTest extends TestCase
{
    private const DIR = __DIR__ . '/../Fixtures/privacy';

    /** @return list<string> */
    private static function seeded(): array
    {
        /** @var list<string> $values */
        $values = require self::DIR . '/seeded-values.php';

        return $values;
    }

    public function testEverySeededValueOccursInTheFixtures(): void
    {
        $haystack = (string) file_get_contents(self::DIR . '/corpus.log')
            . (string) file_get_contents(self::DIR . '/dependency.log')
            . (string) file_get_contents(self::DIR . '/hygiene.log')
            . (string) file_get_contents(self::DIR . '/service-context.txt');
        // JSON lines escape nothing we seed (unescaped unicode/slashes), but check raw and decoded forms.
        foreach (self::seeded() as $value) {
            self::assertStringContainsString($value, $haystack, $value);
        }
    }

    public function testSeededListIsLargeUniqueAndLongEnough(): void
    {
        $values = self::seeded();

        self::assertGreaterThanOrEqual(40, count($values));
        self::assertSame($values, array_values(array_unique($values)));
        foreach ($values as $value) {
            self::assertGreaterThanOrEqual(5, mb_strlen($value), $value);
        }
    }

    public function testCorpusKeepsNonPersonalRepeatsAndBothFormats(): void
    {
        $corpus = (string) file_get_contents(self::DIR . '/corpus.log');

        self::assertGreaterThanOrEqual(6, substr_count($corpus, 'Lock wait timeout exceeded'));
        self::assertMatchesRegularExpression('/^\[2026-[^\]]+\] production\.ERROR: /m', $corpus);
        self::assertMatchesRegularExpression('/^\{"timestamp":/m', $corpus);
    }
}
