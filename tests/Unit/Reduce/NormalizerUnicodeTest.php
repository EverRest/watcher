<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Reduce\Normalizer;

/** S2-02 / F-06: encodings and invisible or look-alike characters must not carry a value past the rules. */
final class NormalizerUnicodeTest extends TestCase
{
    private const ZWSP = "\u{200B}";

    /** @return array<string, array{string, list<string>}> input => fragments that must not survive */
    public static function bypasses(): array
    {
        $z = self::ZWSP;

        return [
            'fullwidth email' => ['mail ｊｏｈｎ.ｓｍｉｔｈ＠ｅｘａｍｐｌｅ.ｃｏｍ sent', ['ｊｏｈｎ', 'john', 'smith']],
            'zero-width in local part' => ["mail john{$z}.smith@example.com sent", ['john', 'smith']],
            'soft hyphen' => ["mail jo\u{00AD}hn.smith@example.com sent", ['john', 'smith', 'jo']],
            'bidi override' => ["mail john\u{202E}.smith@example.com sent", ['john', 'smith']],
            'isolate' => ["mail john\u{2066}.smith@example.com sent", ['john', 'smith']],
            'bom and word joiner' => ["mail jo\u{FEFF}hn\u{2060}.smith@example.com sent", ['john', 'smith']],
            'percent-encoded at' => ['mail jsmith%40example.com sent', ['jsmith']],
            'html numeric at' => ['mail jsmith&#64;example.com sent', ['jsmith']],
            'html hex at' => ['mail jsmith&#x40;example.com sent', ['jsmith']],
            'html named at' => ['mail jsmith&commat;example.com sent', ['jsmith']],
            'small commercial at' => ['mail jsmith﹫example.com sent', ['jsmith']],
            'encoded url credentials' => ['u=https%3A%2F%2Fadmin%3Ahunter%40db.internal%2F x', ['admin', 'hunter']],
            'encoded colon creds' => ['http%3A%2F%2Fuser%3Apass%40host.example%2Fx', ['user', 'pass']],
            'zero-width in scheme' => ["go https{$z}://u:p@host.example/x end", ['u:', 'host.example']],
            'zero-width in token prefix' => ["key sk-ant-api03-abc{$z}defghijklmnop Z", ['defghijklmnop', 'abc']],
            'zero-width splits key' => ["key sk{$z}-ant-api03-abcdefghijklmnop Z", ['abcdefghijklmnop']],
            'idn host' => ['to бухгалтерія.example.ua now', ['бухгалтерія', 'example.ua']],
            'ideographic dots' => ["to db。internal。corp now", ['internal']],
            'zero-width host' => ["to db{$z}.internal.corp now", ['internal']],
            'quoted local part' => ['to "john smith"@example.com now', ['john', 'smith']],
            'fullwidth password key' => ['ｐａｓｓｗｏｒｄ＝hunterxyz', ['hunterxyz']],
            'percent-encoded fullwidth at' => ['mail jsmith%EF%BC%A0example.com sent', ['jsmith']],
            'entity fullwidth at' => ['mail jsmith&#xFF20;example.com sent', ['jsmith']],
            'double percent' => ['mail jsmith%2540example.com sent', ['jsmith']],
            'triple percent' => ['mail jsmith%252540example.com sent', ['jsmith']],
            'entity of percent' => ['mail jsmith&#37;40example.com sent', ['jsmith']],
            'entity of entity' => ['mail jsmith&amp;#64;example.com sent', ['jsmith']],
            'zero-width inside percent' => ["mail jsmith%4{$z}0example.com sent", ['jsmith']],
            'bidi inside entity' => ["mail jsmith&#6\u{202E}4;example.com sent", ['jsmith']],
            'ideographic dot email' => ['mail john@example。com sent', ['example']],
            'halfwidth ideographic dot email' => ['mail john@example｡com sent', ['example']],
            'ideographic dot ipv4' => ['from 10。0。0。1 now', ['10']],
            'fullwidth dot ipv4' => ['from 10．0．0．1 now', ['10']],
            'halfwidth dot host' => ['to api｡internal｡example now', ['internal']],
            'zero-width in password key' => ["pass{$z}word=hunterxyz", ['hunterxyz']],
        ];
    }

    /** @param list<string> $gone */
    #[DataProvider('bypasses')]
    public function testBypassIsClosed(string $in, array $gone): void
    {
        $out = (new Normalizer())->normalize($in);

        foreach ($gone as $fragment) {
            self::assertStringNotContainsString($fragment, $out, $out);
        }
        self::assertSame(1, preg_match('/<(?:EMAIL|HOST|URL|TOKEN|IP)>/', $out), $out);
    }

    #[DataProvider('bypasses')]
    public function testBypassIsClosedWithoutIntl(string $in): void
    {
        // The fold used when ext-intl is missing is exercised on its own, then the rules run on its output.
        $out = (new Normalizer())->normalize(Normalizer::canonical($in, false));

        self::assertSame(1, preg_match('/<(?:EMAIL|HOST|URL|TOKEN|IP)>/', $out), $out);
    }

    public function testNestingIsBoundedAndFast(): void
    {
        foreach ([1, 0] as $jit) {
            ini_set('pcre.jit', (string) $jit);
            $t = microtime(true);
            (new Normalizer())->scan(str_repeat('%25', 1300) . str_repeat('&amp;', 600));
            (new Normalizer())->scan(str_repeat("\u{FDFA}%2540", 700));
            self::assertLessThan(0.5, microtime(true) - $t);
        }
        ini_set('pcre.jit', '1');
        // Five levels is past the bound: the text is still scanned, never looped on.
        (new Normalizer())->normalize('a%25252525252540b.com');
        $this->addToAssertionCount(1);
    }

    public function testDotsBetweenLabelsAreDotsEverywhere(): void
    {
        self::assertSame('a.b', Normalizer::canonical("a\u{3002}b", false));
        self::assertSame('a.b', Normalizer::canonical("a\u{FF61}b", false));
        self::assertSame('a.b', Normalizer::canonical("a\u{FF0E}b", false));
        self::assertSame('a.b', Normalizer::canonical("a\u{FF61}b", true));
        // A CJK sentence stop next to a space or at the end is no dot.
        self::assertSame("接続失敗。 次\u{3002}", Normalizer::canonical("接続失敗。 次\u{3002}", false));
    }

    public function testFormatCharactersNeverSurvive(): void
    {
        $out = (new Normalizer())->normalize("a\u{200B}b\u{202E}c\u{2066}d\u{FEFF}e\u{2060}f\u{00AD}g");

        self::assertSame('abcdefg', $out);
    }

    public function testExpansionCannotGrowPastTheCap(): void
    {
        $out = (new Normalizer())->normalize(str_repeat("\u{FDFA} ", 3000));

        self::assertLessThanOrEqual(Normalizer::MAX_CHARS, mb_strlen($out));
    }

    public function testOrdinaryNonAsciiProseIsKept(): void
    {
        self::assertSame('Помилка з\'єднання з базою', (new Normalizer())->normalize('Помилка з\'єднання з базою'));
        self::assertSame('接続に失敗しました', (new Normalizer())->normalize('接続に失敗しました'));
    }

    public function testPercentSequencesInPlainTextAreOnlyDecodedForPunctuation(): void
    {
        self::assertSame('<N>% done', (new Normalizer())->normalize('100%25 done'));
    }
}
