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
        self::assertSame(1, preg_match('/<(?:EMAIL|HOST|URL|TOKEN)>/', $out), $out);
    }

    #[DataProvider('bypasses')]
    public function testBypassIsClosedWithoutIntl(string $in): void
    {
        // The fold used when ext-intl is missing is exercised on its own, then the rules run on its output.
        $out = (new Normalizer())->normalize(Normalizer::canonical($in, false));

        self::assertSame(1, preg_match('/<(?:EMAIL|HOST|URL|TOKEN)>/', $out), $out);
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
        self::assertSame('<N>%<N> done', (new Normalizer())->normalize('100%25 done'));
    }
}
