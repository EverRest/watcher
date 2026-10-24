<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Reduce\NormalizationException;
use Watcher\Reduce\Normalizer;

/** S2-03 / F-02: one poisoned line must never fail the window. */
final class NormalizerHostileInputTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function hostile(): array
    {
        return [
            'colons' => ['http://' . str_repeat(':', 1450)],
            'a colon' => ['GET failed http://' . str_repeat('a:', 2000)],
            'prefixed' => ['login failed http://a.' . str_repeat(':', 4000)],
            'x scheme' => ['x://a%C:\\' . str_repeat('a:', 2000)],
            'ip-ish' => ['x://1.1.0x1.0xff:' . str_repeat('1:', 2000)],
            'user colons at' => ['http://u' . str_repeat(':a@', 1300)],
            'repeated scheme' => [str_repeat('http://', 580)],
            'repeated userinfo' => [str_repeat('a://b:', 680)],
            'slashes' => ['http://a:' . str_repeat('/?', 2000)],
            'at signs' => ['http://a:b/' . str_repeat('@:', 2000)],
        ];
    }

    #[DataProvider('hostile')]
    public function testBuiltInRulesNeverExhaustTheBacktrackLimit(string $in): void
    {
        foreach ([1, 0] as $jit) {
            ini_set('pcre.jit', (string) $jit);
            $t = microtime(true);
            try {
                (new Normalizer())->scan($in);
            } finally {
                ini_set('pcre.jit', '1');
            }
            self::assertLessThan(0.5, microtime(true) - $t, "jit=$jit");
        }
    }

    public function testNormalizeOrUnscannableReturnsThePlaceholderAndCounts(): void
    {
        $n = new Normalizer();

        self::assertSame('<UNSCANNABLE>', $n->normalizeOrUnscannable("bad \xFF utf8"));
        self::assertSame('fine <N>', $n->normalizeOrUnscannable('fine 1'));
        self::assertSame(1, $n->unscannable);
        self::assertSame('<UNSCANNABLE>', $n->normalizeOrUnscannable("\xC3"));
        self::assertSame(2, $n->unscannable);
    }

    public function testExtraPatternFailureIsUnscannableToo(): void
    {
        $n = new Normalizer(extraPatterns: ['x' => '/(a+)+$/']);
        $in = str_repeat('a', 40) . '!';

        try {
            $n->normalize($in);
            self::markTestSkipped('pattern did not fail on this build');
        } catch (NormalizationException) {
            self::assertSame('<UNSCANNABLE>', $n->normalizeOrUnscannable($in));
        }
    }

    public function testThePlaceholderIsReservedAndSurvivesTheRules(): void
    {
        self::assertSame('x <UNSCANNABLE> y', (new Normalizer())->normalize('x <UNSCANNABLE> y'));
        $this->expectException(\InvalidArgumentException::class);
        new \Watcher\Privacy\Redactor(new Normalizer(), ['unscannable' => '/x/']);
    }
}
