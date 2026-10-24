<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use PHPUnit\Framework\TestCase;
use Watcher\Notify\DiscordText;
use Watcher\Notify\SlackText;

final class DiscordTextTest extends TestCase
{
    private const Z = "\u{200B}";

    /** @return iterable<string, array{string, string, string}> kind, source, expected */
    public static function table(): iterable
    {
        $z = self::Z;
        yield 'plain' => ['finding', 'hello', 'hello'];
        yield 'entities decoded' => ['finding', 'a &amp; b', 'a & b'];
        yield 'lt gets zwsp' => ['finding', 'a &lt;b&gt;', "a <{$z}b>"];
        yield 'markdown chars escaped' => ['finding', 'a_b*c~d|e[f](g)\\h', 'a\\_b\\*c\\~d\\|e\\[f\\]\\(g\\)\\\\h'];
        yield 'bold' => ['severity', '🔴 *critical* (1)', '🔴 **critical** \\(1\\)'];
        yield 'code' => ['finding', 'in `a_b.php:8`', 'in `a_b.php:8`'];
        yield 'link' => ['finding', '<https://x.example/p?a=1&amp;b=2|pkg_x>', '[pkg\\_x](<https://x.example/p?a=1&b=2>)'];
        yield 'header is bold' => ['header', '🔎 a &amp; b', '**🔎 a & b**'];
        yield 'header does not nest bold' => ['header', 'x *y* z', '**x y z**'];
        yield 'heading at start' => ['finding', '# title', '\\# title'];
        yield 'quote at start' => ['finding', '> q', '\\> q'];
        yield 'list at start' => ['finding', '- a', '\\- a'];
        yield 'plus at start' => ['finding', '+ a', '\\+ a'];
        yield 'numbered at start' => ['finding', '1. a', '\\1. a'];
        yield 'line start after newline' => ['finding', "a\n# b\n> c", "a\n\\# b\n\\> c"];
        yield 'mid-line hash untouched' => ['finding', 'issue #12 - fix', 'issue #12 - fix'];
        yield 'bullet char untouched' => ['finding', '• item', '• item'];
        yield 'unmatched star' => ['finding', '2 * 3', '2 \\* 3'];
        yield 'unmatched backtick' => ['finding', 'a ` b', 'a \\` b'];
        yield 'code body decoded, not escaped' => ['finding', '`a&lt;b_c`', '`a<b_c`'];
        yield 'url with angle dropped to text' => ['finding', '<https://x.example/a&gt;b|l>', "<{$z}https://x.example/a>b\\|l>"];
        yield 'zwsp kept' => ['finding', "x@\u{200B}y", "x@\u{200B}y"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('table')]
    public function testTable(string $kind, string $source, string $expected): void
    {
        self::assertSame($expected, DiscordText::section($kind, $source));
    }

    public function testLineStartContinuesAfterAToken(): void
    {
        // "*a* - b": the dash is mid-line, not at the start of a line.
        self::assertSame('**a** - b', DiscordText::section('finding', '*a* - b'));
    }

    public function testModelTextIsInert(): void
    {
        $seeds = ['__x__', '@everyone', '<@123>', '<#123>', '<:e:1>', '[x](http://y.com)', '|| spoiler ||', '# heading', '> quote', '~~s~~', '<https://a.com|x>'];
        foreach ($seeds as $seed) {
            $out = DiscordText::section('finding', 'pre ' . SlackText::escape($seed) . ' post');

            self::assertDoesNotMatchRegularExpression('/(?<!\\\\)(?:\*\*|__|~~|\|\|)/', $out, $seed);
            self::assertDoesNotMatchRegularExpression('/(?<!\\\\)\[[^\]]*\](?!\\\\)\(/', $out, "live link for $seed");
            self::assertDoesNotMatchRegularExpression('~<[@#:a!&]~', $out, "mention/emoji syntax for $seed");
            self::assertDoesNotMatchRegularExpression('~@(?!\x{200B})~u', $out, $seed);
            self::assertDoesNotMatchRegularExpression('~https?://~i', $out, $seed);
        }
    }

    public function testStarsInModelTextAreInert(): void
    {
        self::assertSame("pre \u{2217}\u{2217}x\u{2217}\u{2217} post", DiscordText::section('finding', 'pre ' . SlackText::escape('**x**') . ' post'));
    }

    public function testModelTextAtLineStartIsInert(): void
    {
        $out = DiscordText::section('finding', SlackText::escape('# heading'));

        self::assertStringStartsWith('\\#', $out);
    }

    public function testForgedLinkTokenInModelTextIsText(): void
    {
        $out = DiscordText::section('finding', 'x ' . SlackText::escape('<http://evil.com|click>') . ' y');

        self::assertStringNotContainsString('](<', $out);
        self::assertStringContainsString('<' . self::Z . 'http:' . self::Z . '//evil.' . self::Z . 'com\\|click>', $out);
    }

    public function testPlainPathDefangsAndEscapes(): void
    {
        $z = self::Z;

        self::assertSame("see http:{$z}//a.{$z}com <{$z}@{$z}1> \\*x\\*", DiscordText::plain('see http://a.com <@1> *x*'));
    }
}
