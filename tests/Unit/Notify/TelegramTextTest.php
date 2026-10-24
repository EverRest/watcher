<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use PHPUnit\Framework\TestCase;
use Watcher\Notify\SlackText;
use Watcher\Notify\TelegramText;

final class TelegramTextTest extends TestCase
{
    private const Z = "\u{200B}";

    /** @return iterable<string, array{string, string, string}> kind, source, expected */
    public static function table(): iterable
    {
        yield 'plain' => ['finding', 'hello', 'hello'];
        yield 'entities decoded then escaped' => ['finding', 'a &amp; b &lt;c&gt;', 'a &amp; b &lt;c&gt;'];
        yield 'bare ampersand' => ['finding', 'a & b', 'a &amp; b'];
        yield 'bold' => ['severity', '🔴 *critical* (1)', '🔴 <b>critical</b> (1)'];
        yield 'code' => ['finding', 'in `a.php:8`', 'in <code>a.php:8</code>'];
        yield 'code body decoded' => ['finding', '`a &lt; b`', '<code>a &lt; b</code>'];
        yield 'link' => ['finding', '<https://x.example/p?a=1&amp;b=2|pkg &amp; co>', '<a href="https://x.example/p?a=1&amp;b=2">pkg &amp; co</a>'];
        yield 'header is bold' => ['header', '🔎 a &amp; b', '<b>🔎 a &amp; b</b>'];
        yield 'header does not nest bold' => ['header', 'x *y* z', '<b>x y z</b>'];
        yield 'unmatched star' => ['finding', '2 * 3', '2 * 3'];
        yield 'unmatched backtick' => ['finding', 'a ` b', 'a ` b'];
        yield 'newline kept' => ['finding', "a\nb", "a\nb"];
        yield 'zwsp kept' => ['finding', "x@\u{200B}y", "x@\u{200B}y"];
        yield 'quote untouched' => ['finding', 'say "hi"', 'say "hi"'];
        yield 'url with quote dropped to text' => ['finding', '<https://x.example/a"b|l>', '&lt;https://x.example/a"b|l&gt;'];
        yield 'url with encoded lt dropped to text' => ['finding', '<https://x.example/a&lt;b|l>', '&lt;https://x.example/a&lt;b|l&gt;'];
        yield 'non-http scheme is text' => ['finding', '<javascript:alert(1)|x>', '&lt;javascript:alert(1)|x&gt;'];
        yield 'footer' => ['footer', '— 12 tokens', '— 12 tokens'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('table')]
    public function testTable(string $kind, string $source, string $expected): void
    {
        self::assertSame($expected, TelegramText::section($kind, $source));
    }

    public function testModelTextIsInert(): void
    {
        $seeds = ['<b>x</b>', '&amp;', 'http://a.example', 'www.a.example', 'host.example', '@everyone', '<a href="http://a.example">x</a>', '*b*', '`c`'];
        foreach ($seeds as $seed) {
            $out = TelegramText::section('finding', 'pre ' . SlackText::escape($seed) . ' post');

            $withoutAllowed = (string) preg_replace('~</?(?:b|i|code)>~', '', $out);
            self::assertStringNotContainsString('<a ', $out, $seed);
            self::assertDoesNotMatchRegularExpression('/<(?!\/?(?:b|i|code)>)/', $withoutAllowed, "raw < in output for $seed");
            self::assertDoesNotMatchRegularExpression('~https?://~i', $out, $seed);
            self::assertDoesNotMatchRegularExpression('~\bwww\.~i', $out, $seed);
            self::assertDoesNotMatchRegularExpression('~[a-z0-9]\.(?!\x{200B})[a-z]{2,}\b~iu', $out, $seed);
            self::assertDoesNotMatchRegularExpression('~@(?!\x{200B})\w~u', $out, $seed);
        }
    }

    public function testForgedLinkTokenInModelTextIsText(): void
    {
        $source = 'x ' . SlackText::escape('<http://evil.example|click>') . ' y';

        $out = TelegramText::section('finding', $source);

        self::assertStringNotContainsString('<a', $out);
        self::assertStringContainsString('&lt;http:' . self::Z . '//evil.' . self::Z . 'example|click&gt;', $out);
    }

    public function testOnlyAllowedTagsEverAppear(): void
    {
        $out = TelegramText::section('finding', '*b* `c` <https://x.example|l> &lt;i&gt; &lt;script&gt;');

        preg_match_all('~<(/?)([a-z]+)~', $out, $m);
        self::assertSame([], array_diff($m[2], ['b', 'i', 'code', 'a']));
    }

    public function testCutSourceStaysBalanced(): void
    {
        $source = '*bold text* and `code` and <https://x.example/p|label> and &amp; more';
        for ($n = 0, $len = mb_strlen($source); $n <= $len; ++$n) {
            $out = TelegramText::section('finding', mb_substr($source, 0, $n));

            foreach (['b', 'code', 'a'] as $tag) {
                self::assertSame(substr_count($out, "<$tag"), substr_count($out, "</$tag>"), "unbalanced <$tag> at cut $n: $out");
            }
            self::assertDoesNotMatchRegularExpression('/&(?!(?:amp|lt|gt);)/', $out, "bare & at cut $n");
        }
    }

    public function testPlainPathDefangsAndEscapes(): void
    {
        $out = TelegramText::plain('see http://a.example <b> & @x');

        self::assertSame('see http:' . self::Z . '//a.' . self::Z . 'example &lt;b&gt; &amp; @' . self::Z . 'x', $out);
    }
}
