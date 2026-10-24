<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Reduce\Normalizer;

/** S2-01 / S2-04: credentials without a header keyword, whatever their length or case. */
final class NormalizerCredentialsTest extends TestCase
{
    /** @return array<string, array{string, string}> input => the secret that must not survive */
    public static function secrets(): array
    {
        return [
            'password=' => ['login failed password=hunterxyz now', 'hunterxyz'],
            'PASSWORD=' => ['PASSWORD=hunterxyz', 'hunterxyz'],
            'password short' => ['password=ab', 'ab'],
            'pwd=' => ['pwd=hunterxyz', 'hunterxyz'],
            'passwd:' => ['passwd: hunterxyz', 'hunterxyz'],
            'passphrase' => ['passphrase=correct-horse', 'correct-horse'],
            'secret:' => ['secret: correcthorsebattery', 'correcthorsebattery'],
            'client_secret=' => ['client_secret=hunterxyz&x=1', 'hunterxyz'],
            'api_key short' => ['api_key=k1', 'k1'],
            'apikey' => ['apikey=abcdef', 'abcdef'],
            'access_key' => ['access_key=abcdef', 'abcdef'],
            'private_key' => ['private_key=abcdef', 'abcdef'],
            'token short' => ['token=Ab3dEf6hIj9lMn2p', 'Ab3dEf6hIj9lMn2p'],
            'access_token' => ['access_token=abc', 'abc'],
            'mixed case key' => ['ApiKey: abcdef', 'abcdef'],
            'pgsql dsn' => ['pgsql:host=db;dbname=shop;user=app;password=hunterxyz;sslmode=require', 'hunterxyz'],
            'mysql dsn' => ['mysql:host=db;dbname=shop;user=app;password=hunterxyz', 'hunterxyz'],
            'json password' => ['{"username":"jsmith","password":"correcthorse"}', 'correcthorse'],
            'json password with space' => ['{"password":"correct horse"}', 'horse'],
            'json secret' => ['{"secret":"abc123"}', 'abc123'],
            'json token' => ['{"token": "abc123"}', 'abc123'],
            'single quoted' => ["password='hunterxyz'", 'hunterxyz'],
            'form body' => ['username=jsmith&password=hunter2xyz&next=/', 'hunter2xyz'],
            'query token' => ['GET /api/v1/x?token=abcdef&y=1 500', 'abcdef'],
            'query key' => ['GET /api/x?a=1&key=abcdef 500', 'abcdef'],
            'query sig' => ['GET /dl?sig=abcdef 403', 'abcdef'],
            'header token' => ['X-Amz-Security-Token: IQoJb3JpZ2luX2VjEJr', 'IQoJb3JpZ2luX2VjEJr'],
            'header key' => ['x-custom-key: abcdef', 'abcdef'],
            'header signature' => ['X-Signature: abcdef', 'abcdef'],
        ];
    }

    #[DataProvider('secrets')]
    public function testValueIsMaskedAndKeyKept(string $in, string $secret): void
    {
        $out = (new Normalizer())->normalize($in);

        self::assertStringNotContainsString($secret, $out);
        self::assertStringContainsString('<TOKEN>', $out);
    }

    public function testKeyNameStays(): void
    {
        $n = new Normalizer();

        self::assertSame('password=<TOKEN>;x', $n->normalize('password=hunter2;x'));
        self::assertSame('{"password":"<TOKEN>"}', $n->normalize('{"password":"hunter2"}'));
        self::assertSame('a=<N>&token=<TOKEN>&b=<N>', $n->normalize('a=1&token=zzz&b=2'));
        self::assertStringEndsWith(': <TOKEN>', $n->normalize('X-Amz-Security-Token: IQoJb3JpZ2luX2VjEJr'));
    }

    public function testSecretsAreMaskedEvenWhenEveryEventRepeatsThem(): void
    {
        // k-anonymity keeps a value that repeats in every event; the Normalizer must have removed it before that.
        $n = new Normalizer();
        $a = $n->normalize('retry 1 dsn password=hunterxyz');
        $b = $n->normalize('retry 2 dsn password=hunterxyz');

        self::assertSame($a, $b);
        self::assertStringNotContainsString('hunterxyz', $a);
    }

    public function testPlainProseIsLeftAlone(): void
    {
        $n = new Normalizer();

        self::assertSame('Token expired', $n->normalize('Token expired'));
        self::assertSame('password authentication failed for user', $n->normalize('password authentication failed for user'));
        self::assertSame('Basic auth failed', $n->normalize('Basic auth failed'));
    }

    public function testRelativeQueryValuesAreMaskedKeysKept(): void
    {
        $out = (new Normalizer())->normalize('GET /search?q=olena+shevchenko&city=Kyiv 500');

        self::assertStringNotContainsString('olena', $out);
        self::assertStringNotContainsString('Kyiv', $out);
        self::assertStringContainsString('q=', $out);
        self::assertStringContainsString('city=', $out);
    }

    public function testUserAtSingleLabelHostIsAnEmail(): void
    {
        $n = new Normalizer();

        self::assertSame('Permission denied for <EMAIL>', $n->normalize('Permission denied for jsmith@bastion'));
        self::assertSame('<EMAIL>', $n->normalize('jane@localhost'));
        self::assertSame('<EMAIL>', $n->normalize('"john smith"@example.com'));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function queries(): array
    {
        return [
            'key without value first' => ['GET /x?a&b=olenaS 500', ['olenaS']],
            'after a masked token' => ['GET /x?token=zzz&q=olenaS 500', ['olenaS']],
            'placeholder in the middle' => ['GET /x?a=1&token=zzz&q=olenaS&r=kyivS 500', ['olenaS', 'kyivS']],
            'semicolon separated' => ['GET /x?a=1;q=olenaS 500', ['olenaS']],
            'plus and percent encoded' => ['GET /x?q=olena+shevchenkoS&c=kyiv%20cityS&d=a%2Bb S', ['shevchenkoS', 'cityS']],
            'repeated keys' => ['GET /x?q=olenaS&q=petroS&q=ivanS 500', ['olenaS', 'petroS', 'ivanS']],
            'empty key parts' => ['GET /x?&&q=olenaS&=zS 500', ['olenaS', 'zS']],
        ];
    }

    /** @param list<string> $gone */
    #[DataProvider('queries')]
    public function testWholeQueryIsMasked(string $in, array $gone): void
    {
        $out = (new Normalizer())->normalize($in);

        foreach ($gone as $g) {
            self::assertStringNotContainsString($g, $out, $out);
        }
    }

    public function testQueryKeysStayAndKeyOnlyPartsAreKept(): void
    {
        self::assertSame('GET /x?a&b=<STR> <N>', (new Normalizer())->normalize('GET /x?a&b=olena 500'));
    }

    public function testQueryRuleIsLinear(): void
    {
        foreach ([1, 0] as $jit) {
            ini_set('pcre.jit', (string) $jit);
            foreach (['/x' . str_repeat('?a&', 1300), '/x?' . str_repeat('a=b&', 1000), '/x' . str_repeat('?', 4000), 'a/' . str_repeat('?&=', 1300)] as $in) {
                $t = microtime(true);
                (new Normalizer())->scan($in);
                self::assertLessThan(0.5, microtime(true) - $t);
            }
        }
        ini_set('pcre.jit', '1');
    }
}
