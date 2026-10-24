<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Notify;

use PHPUnit\Framework\TestCase;
use Watcher\Notify\SlackText;

final class SlackTextTest extends TestCase
{
    private const Z = "\u{200B}";

    public function testDefangBreaksAutolinkableTokens(): void
    {
        self::assertSame('http:' . self::Z . '//a.' . self::Z . 'com', SlackText::defang('http://a.com'));
        self::assertSame('www' . self::Z . '.' . 'a.' . self::Z . 'com', SlackText::defang('www.a.com'));
        self::assertSame('host.' . self::Z . 'com', SlackText::defang('host.com'));
        self::assertSame('@' . self::Z . 'everyone', SlackText::defang('@everyone'));
    }

    public function testDefangBreaksInternationalTlds(): void
    {
        self::assertSame('пример.' . self::Z . 'рф', SlackText::defang('пример.рф'));
        self::assertSame('example.' . self::Z . '中国', SlackText::defang('example.中国'));
        self::assertSame('a.' . self::Z . 'xn--p1ai', SlackText::defang('a.xn--p1ai'));
    }

    public function testDefangBreaksIdnaDotsAndCombiningMarks(): void
    {
        self::assertSame("evil.\u{200B}com", SlackText::defang("evil.com"));
        self::assertSame("evil\u{3002}\u{200B}com", SlackText::defang("evil\u{3002}com"));
        self::assertSame("evil\u{FF0E}\u{200B}com", SlackText::defang("evil\u{FF0E}com"));
        self::assertSame("www\u{200B}\u{FF61}evil\u{FF61}\u{200B}com", SlackText::defang("www\u{FF61}evil\u{FF61}com"));
        self::assertSame("cafe\u{301}.\u{200B}com", SlackText::defang("cafe\u{301}.com"));
    }

    public function testDefangFailsClosedOnLowercaseHostsWithUnlistedTlds(): void
    {
        self::assertSame('mailer.' . self::Z . 'send', SlackText::defang('mailer.send'));
        self::assertSame('a.b.' . self::Z . 'newtld', SlackText::defang('a.b.newtld'));
    }

    public function testDefangKeepsCodeIdentifiers(): void
    {
        self::assertSame('Mailer.send failed in Foo.baz() via mailer.sendAll', SlackText::defang('Mailer.send failed in Foo.baz() via mailer.sendAll'));
    }

    public function testDefangKeepsFileNames(): void
    {
        self::assertSame('Foo.php:88 in a.json', SlackText::defang('Foo.php:88 in a.json'));
    }

    public function testDefangLeavesEntitiesAndMarkupAlone(): void
    {
        self::assertSame('a < b & *c* `d`', SlackText::defang('a < b & *c* `d`'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function corpus(): iterable
    {
        $z = self::Z;
        yield 'plain' => ['hello world', 'hello world'];
        yield 'whitespace' => ["  a \n\t b  ", 'a b'];
        yield 'amp' => ['a & b', 'a &amp; b'];
        yield 'tags' => ['<b>x</b>', '&lt;b&gt;x&lt;/b&gt;'];
        yield 'backtick' => ['`x`', "'x'"];
        yield 'at' => ['@here', "@{$z}here"];
        yield 'url' => ['https://a.com/x', "https:{$z}//a.{$z}com/x"];
        yield 'www' => ['www.a.com', "www{$z}.a.{$z}com"];
        yield 'host' => ['see host.com now', "see host.{$z}com now"];
        yield 'file' => ['X.php:88', 'X.php:88'];
        yield 'forged link' => ['<http://evil.com|click>', "&lt;http:{$z}//evil.{$z}com|click&gt;"];
        yield 'forged mention' => ['<@123>', "&lt;@{$z}123&gt;"];
        yield 'markdown' => ['**x** [y](z)', "\u{2217}\u{2217}x\u{2217}\u{2217} [y](z)"];
        yield 'lone star neutralised too' => ['glob *.log', "glob \u{2217}.log"];
        yield 'bold' => ['a *b* c', "a \u{2217}b\u{2217} c"];
        yield 'italic' => ['an _italic_ word', "an \u{FF3F}italic\u{FF3F} word"];
        yield 'strike' => ['a ~gone~ b', "a \u{223C}gone\u{223C} b"];
        yield 'identifiers kept' => ['user_id __init__ ~/.cfg a_b_c', 'user_id __init__ ~/.cfg a_b_c'];
        yield 'emoji spoof' => ['all :white_check_mark: ok', "all :{$z}white_check_mark: ok"];
        yield 'clock kept' => ['at 12:30:45 on host:8080', 'at 12:30:45 on host:8080'];
        yield 'emoji' => ['🔴 é', '🔴 é'];
        yield 'empty' => ['', ''];
        yield 'ip' => ['10.0.0.1', '10.0.0.1'];
        yield 'dotted word' => ['v1.2.beta', "v1.2.{$z}beta"];
        yield 'email' => ['a@b.com', "a@{$z}b.{$z}com"];
        yield 'ampersand entity' => ['&amp;', '&amp;amp;'];
        yield 'mixed' => ["x <y> & `z` @q://w", "x &lt;y&gt; &amp; 'z' @{$z}q:{$z}//w"];
    }

    public function testFieldStarCannotPairWithAStarTheRendererAdds(): void
    {
        $line = '*Probable cause: ' . SlackText::escape('a*') . '* then ' . SlackText::escape('*b');

        self::assertSame(2, substr_count($line, '*'));
    }

    public function testTldListHasNoDuplicates(): void
    {
        $tlds = (new \ReflectionClassConstant(SlackText::class, 'TLDS'))->getValue();
        $all = explode('|', $tlds);

        self::assertSame(array_values(array_unique($all)), $all);
    }

    public function testPlainRestoresMrkdwnLookalikes(): void
    {
        self::assertSame('a *b* _c_ ~d~', SlackText::plain(SlackText::escape('a *b* _c_ ~d~')));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('corpus')]
    public function testEscapeIsUnchanged(string $in, string $expected): void
    {
        self::assertSame($expected, SlackText::escape($in));
    }

    public function testPlainNeverRestoresControlSequences(): void
    {
        foreach (['&lt;!channel&gt;', '<!channel>', '<@U123ABC>', '&amp;lt;!here&amp;gt;', '<https://evil.example|Reset password>'] as $x) {
            $plain = SlackText::plain(SlackText::escape($x));

            self::assertStringNotContainsString('<', $plain, $x);
            self::assertStringNotContainsString('>', $plain, $x);
            self::assertDoesNotMatchRegularExpression('#://|@(?!\x{200B})#u', $plain, $x);
        }
        // One pass: an escaped ampersand is an ampersand, never the start of another entity.
        self::assertSame('&lt;!channel&gt;', SlackText::plain('&amp;lt;!channel&amp;gt;'));
    }

    public function testFormatCharactersAreStrippedBeforeTheDefangSpaceIsAdded(): void
    {
        // A bidi override and a zero-width space inside a host do not hide it from the defang.
        $out = SlackText::defang("evil\u{202E}\u{200B}.com");
        self::assertStringNotContainsString("\u{202E}", $out);
        self::assertSame("evil.\u{200B}com", $out);
        self::assertStringNotContainsString("\u{2066}", SlackText::escape("x\u{2066}y"));
        self::assertSame("a@\u{200B}b", SlackText::escape("a\u{FEFF}@b"));
    }
}
