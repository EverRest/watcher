<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Select;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Select\Re2Compat;

final class Re2CompatTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function compatible(): array
    {
        return [
            'class name' => ['App\\\\Exceptions\\\\\w+Exception'],
            'alternation' => ['(?:Timeout|Refused)Exception'],
            'lazy quantifier' => ['a.*?b'],
            'named group' => ['(?P<n>x)y'],
            'inline flag group' => ['(?i:abc)'],
            'escaped plus after quantifier' => ['a+\+'],
            'class with plus' => ['[a+]+'],
            'escaped backslash then paren' => ['\\\\(?:x)'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function incompatible(): array
    {
        return [
            'lookahead' => ['foo(?=bar)'],
            'negative lookahead' => ['foo(?!bar)'],
            'lookbehind' => ['(?<=a)b'],
            'negative lookbehind' => ['(?<!a)b'],
            'backreference' => ['(a)\1'],
            'named backreference' => ['(?P<n>a)(?P=n)'],
            'k backreference' => ['(?<n>a)\k<n>'],
            'atomic group' => ['(?>a+)b'],
            'possessive plus' => ['a++b'],
            'possessive star' => ['a*+b'],
            'possessive brace' => ['a{2}+b'],
            'keep out' => ['foo\Kbar'],
            'recursion' => ['\((?R)?\)'],
            'subroutine call' => ['(a)(?1)'],
            'conditional' => ['(a)?(?(1)b|c)'],
            'verb' => ['(*FAIL)a'],
            'comment group' => ['a(?#x)b'],
            'branch reset' => ['(?|a|b)'],
            'G anchor' => ['\Gfoo'],
            'horizontal space' => ['a\\hb'],
            'not horizontal space' => ['a\\Hb'],
            'not vertical space' => ['a\\Vb'],
            'vertical space' => ['a\\vb'],
            'control char' => ['a\\cAb'],
            'escape char' => ['a\\eb'],
        ];
    }

    #[DataProvider('compatible')]
    public function testAcceptsRe2Syntax(string $body): void
    {
        self::assertTrue(Re2Compat::supports($body), $body);
    }

    #[DataProvider('incompatible')]
    public function testRejectsPcreOnlySyntax(string $body): void
    {
        self::assertFalse(Re2Compat::supports($body), $body);
    }
}
