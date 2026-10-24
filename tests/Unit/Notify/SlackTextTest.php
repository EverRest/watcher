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
        self::assertSame('http:' . self::Z . '//a.' . self::Z . 'example', SlackText::defang('http://a.example'));
        self::assertSame('www' . self::Z . '.' . 'a.' . self::Z . 'example', SlackText::defang('www.a.example'));
        self::assertSame('host.' . self::Z . 'example', SlackText::defang('host.example'));
        self::assertSame('@' . self::Z . 'everyone', SlackText::defang('@everyone'));
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
        yield 'url' => ['https://a.example/x', "https:{$z}//a.{$z}example/x"];
        yield 'www' => ['www.a.example', "www{$z}.a.{$z}example"];
        yield 'host' => ['see host.example now', "see host.{$z}example now"];
        yield 'file' => ['X.php:88', 'X.php:88'];
        yield 'forged link' => ['<http://evil.example|click>', "&lt;http:{$z}//evil.{$z}example|click&gt;"];
        yield 'forged mention' => ['<@123>', "&lt;@{$z}123&gt;"];
        yield 'markdown' => ['**x** [y](z)', "\u{2217}\u{2217}x\u{2217}\u{2217} [y](z)"];
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
        yield 'email' => ['a@b.example', "a@{$z}b.{$z}example"];
        yield 'ampersand entity' => ['&amp;', '&amp;amp;'];
        yield 'mixed' => ["x <y> & `z` @q://w", "x &lt;y&gt; &amp; 'z' @{$z}q:{$z}//w"];
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
}
