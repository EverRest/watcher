<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Reduce\NormalizationException;
use Watcher\Reduce\Normalizer;

final class NormalizerTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        return [
            'email' => ['mail john@example.com failed', 'mail <EMAIL> failed'],
            'email inside url query' => ['GET https://api.example.org/x?e=a@b.com failed', 'GET <URL> failed'],
            'uuid' => ['order 123e4567-e89b-12d3-a456-426614174000 gone', 'order <ID> gone'],
            'hex 8+' => ['sha deadbeef12 bad', 'sha <ID> bad'],
            'short hex untouched' => ['code abc123', 'code abc<N>'],
            'ipv4' => ['from 10.0.12.7 refused', 'from <IP> refused'],
            'ipv6 full' => ['from 2001:db8::1 refused', 'from <IP> refused'],
            'ipv6 loopback' => ['bind ::1 failed', 'bind <IP> failed'],
            'clock time is not ipv6' => ['at 12:30:45 boom', 'at <N>:<N>:<N> boom'],
            'class method untouched' => ['Foo::bar failed', 'Foo::bar failed'],
            'jwt' => ['auth eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl bad', 'auth <TOKEN> bad'],
            'jwt with hex-only segment' => ['t eyJhbGciOi.deadbeef00.c2ln', 't <TOKEN>'],
            'pan' => ['card 4111 1111 1111 1111 declined', 'card <PAN> declined'],
            'pan dashes' => ['card 4111-1111-1111-1111 declined', 'card <PAN> declined'],
            'digits' => ['took 1532 ms for 3 rows', 'took <N> ms for <N> rows'],
            'digits in word v2' => ['api v2 down', 'api v<N> down'],
            'sqlstate' => ['SQLSTATE[40001]: deadlock', 'SQLSTATE[<N>]: deadlock'],
            'long double-quoted' => ['bad "' . str_repeat('x', 33) . '" here', 'bad <STR> here'],
            'short quoted kept' => ['bad "' . str_repeat('x', 32) . '" here', 'bad "' . str_repeat('x', 32) . '" here'],
            'apostrophes are not quotes' => [
                "it's a long sentence that goes on and on and it can't stop",
                "it's a long sentence that goes on and on and it can't stop",
            ],
            'whitespace' => ["  a \t b\n\n c  ", 'a b c'],
            'placeholder digits protected' => ['GET http://db1/x', 'GET <URL>'],
        ];
    }

    #[DataProvider('cases')]
    public function testDefaultTable(string $in, string $out): void
    {
        self::assertSame($out, (new Normalizer())->normalize($in));
    }

    /** @return array<string, array{string, string}> */
    public static function urlCases(): array
    {
        return [
            'undeclared single label host' => ['calling http://payments/v1/charge?id=7 now', 'calling <URL> now'],
            'undeclared single label with port' => ['http://redis:6379/0', '<URL>'],
            'undeclared single label with credentials' => ['http://user:pa55@db/x', '<URL>'],
            'declared single label' => ['calling http://core/v1?id=7 now', 'calling <URL:core> now'],
            'declared with port and credentials' => ['http://u:p1@core:8080/x', '<URL:core>'],
            'declared plus domain suffix' => ['http://core.example-ns.vendor.net/x', '<URL:core.example-ns.vendor.net>'],
            'declared name is case-insensitive' => ['http://CORE.svc/x', '<URL:core.svc>'],
            'person name under a dependency domain' => ['http://anna-kowalski.vendor.net/api', '<URL>'],
            'tenant id under a dependency domain' => ['http://tenant-48213.vendor.net/api', '<URL>'],
            'user id single label' => ['http://user48213/api', '<URL>'],
            'declared name only as a suffix label' => ['http://x.core/api', '<URL>'],
            'declared name as a prefix of another name' => ['http://corex/api', '<URL>'],
            'credentials on public host' => ['https://user:pass@api.vendor.io/x', '<URL>'],
            'person-like host dropped' => ['see https://john-smith.example.com/me', 'see <URL>'],
            'declared dependency' => ['https://api.stripe.com/v1/x', '<URL:api.stripe.com>'],
            'dependency domain alone keeps nothing' => ['https://eu.cdn.vendor.net/a', '<URL>'],
            'dependency domain itself keeps nothing' => ['https://vendor.net/a', '<URL>'],
            'suffix must be label boundary' => ['https://evilvendor.net/a', '<URL>'],
            'ipv4 host' => ['http://10.1.2.3:8080/x', '<IP>'],
            'ipv6 host' => ['http://[2001:db8::1]:80/x', '<IP>'],
            'host is lowercased' => ['HTTPS://API.STRIPE.COM/x', '<URL:api.stripe.com>'],
            'trailing sentence punctuation' => ['see http://core/x.', 'see <URL:core>.'],
            'in parentheses' => ['(http://core/x)', '(<URL:core>)'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function bareHostCases(): array
    {
        return [
            'person-like host' => ['Could not resolve host: john-smith.example.com', 'Could not resolve host: <HOST>'],
            'person-like host org' => ['Failed to connect to olena-shevchenko.example.org', 'Failed to connect to <HOST>'],
            'dependency domain alone keeps nothing' => ['svc-x.billing.svc.cluster.local', '<HOST>'],
            'person under the default domain' => ['dial anna-kowalski.svc.cluster.local', 'dial <HOST>'],
            'tenant id under the default domain' => ['dial tenant-48213.svc.cluster.local', 'dial <HOST>'],
            'declared plus default domain' => ['dial core-api.example-ns.svc.cluster.local', 'dial <HOST:core-api.example-ns.svc.cluster.local>'],
            'declared dependency' => ['dns api.stripe.com down', 'dns <HOST:api.stripe.com> down'],
            'host with trailing dot' => ['connect to a.example.com.', 'connect to <HOST>.'],
            'email first' => ['mail john@example.com failed', 'mail <EMAIL> failed'],
            'ip stays ip' => ['from 10.0.12.7 refused', 'from <IP> refused'],
            'zip is a real tld' => ['fetching evil.zip now', 'fetching <HOST> now'],
            'sh is a real tld' => ['fetching evil.sh now', 'fetching <HOST> now'],
            'py is a real tld' => ['fetching evil.py now', 'fetching <HOST> now'],
            'md is a real tld' => ['fetching evil.md now', 'fetching <HOST> now'],
            'file name' => ['in config.php failed', 'in config.php failed'],
            'file name nested' => ['in app/x.service.json failed', 'in app/x.service.json failed'],
            'version' => ['upgrade 1.2.3 and v2.0', 'upgrade <N>.<N>.<N> and v<N>.<N>'],
            'class name' => ['App\\Foo.bar failed', 'App\\Foo.bar failed'],
            'clock time' => ['at 12:30:45 boom', 'at <N>:<N>:<N> boom'],
            'url still url' => ['see https://john-smith.example.com/me', 'see <URL>'],
        ];
    }

    #[DataProvider('bareHostCases')]
    public function testBareHosts(string $in, string $out): void
    {
        $n = new Normalizer(['api.stripe.com', 'core-api'], ['svc.cluster.local']);
        self::assertSame($out, $n->normalize($in));
    }

    #[DataProvider('urlCases')]
    public function testUrls(string $in, string $out): void
    {
        $n = new Normalizer(['api.stripe.com', 'core'], ['vendor.net']);
        self::assertSame($out, $n->normalize($in));
    }

    public function testUnknownHostIsDroppedWithoutLists(): void
    {
        self::assertSame('<URL>', (new Normalizer())->normalize('https://api.stripe.com/v1'));
    }

    /** @return array<string, array{string, string}> */
    public static function placeholderShapedInput(): array
    {
        return [
            'mailto in angle brackets' => ['send failed <mailto:anna.kowalski@example.com> retry', 'send failed <mailto:<EMAIL>> retry'],
            'hex id after a colon' => ['auth <tok:a1b2c3d4e5f6a7b8> rejected', 'auth <tok:<ID>> rejected'],
            'lowercase fake url placeholder' => ['see <url:john-smith.example.com> now', 'see <url:<HOST>> now'],
            'uppercase fake email placeholder' => ['see <EMAIL:john-smith.example.com> now', 'see <EMAIL:<HOST>> now'],
            'fake host placeholder with a name' => ['see <HOST:anna.kowalski@example.com> now', 'see <HOST:<EMAIL>> now'],
            'angle brackets around a url' => ['see <http://core-api/x> now', 'see <<URL>> now'],
        ];
    }

    #[DataProvider('placeholderShapedInput')]
    public function testPlaceholderShapedInputIsNotTrusted(string $in, string $out): void
    {
        self::assertSame($out, (new Normalizer())->normalize($in));
    }

    /** @return array<string, array{string, string, list<string>}> */
    public static function credentialedUrls(): array
    {
        return [
            'slash in password' => ['connect mysql://svc:pa/ss@db.internal.example.com:3306/app failed', 'connect <URL> failed', ['pa/', 'ss', 'internal']],
            'colon and hash in password' => ['dsn postgres://admin:p:a#ss@pg.example.com/db down', 'dsn <URL> down', ['p:a', '#ss', 'pg.example']],
            'question mark in password' => ['url http://bob:se?cret@host.example.org/x', 'url <URL>', ['se?', 'cret', 'host.example']],
            'at sign in password' => ['url http://bob:p@ss@host.example.org/x', 'url <URL>', ['p@', 'ss@', 'host.example']],
            'digits-only password' => ['url http://bob:1234@host.example.org/x', 'url <URL>', ['1234', 'host.example']],
        ];
    }

    /** @param list<string> $mustNotRemain */
    #[DataProvider('credentialedUrls')]
    public function testCredentialedUrlsAreMaskedWholeWhateverThePassword(string $in, string $out, array $mustNotRemain): void
    {
        $got = (new Normalizer())->normalize($in);
        self::assertSame($out, $got);
        foreach ($mustNotRemain as $fragment) {
            self::assertStringNotContainsString($fragment, $got);
        }
    }

    public function testPortsAndEmailsInQueryDoNotTriggerTheCredentialRule(): void
    {
        $n = new Normalizer(['core-api']);
        self::assertSame('<URL:core-api>', $n->normalize('http://core-api/users?email=x@y.com'));
        self::assertSame('<URL:core-api>', $n->normalize('http://core-api:8080/x?e=a@b.com'));
        self::assertSame('<URL:core-api>', $n->normalize('http://core-api:8080'));
    }

    public function testNumbersCanBeKeptWhileEverythingElseStaysMasked(): void
    {
        $n = (new Normalizer())->keepingNumbers();

        self::assertSame(
            '412 errors from <IP> at <EMAIL> card <PAN> since 06:00',
            $n->normalize('412 errors from 10.0.0.1 at a@b.com card 4111 1111 1111 1111 since 06:00'),
        );
        // The default instance is untouched.
        self::assertSame('<N> errors', (new Normalizer())->normalize('412 errors'));
    }

    /** @return array<string, array{string}> */
    public static function adversarial(): array
    {
        return [
            'dash dot' => [str_repeat('a-.', 1400)],
            'dot labels' => [str_repeat('a.', 2000)],
            'at signs' => [str_repeat('a@', 2000)],
            'dash labels then at' => [str_repeat('a-', 2000) . '@'],
            'hundred KB line' => [str_repeat('a-.', 34000)],
            'multibyte' => [str_repeat('é-.', 3000)],
        ];
    }

    #[DataProvider('adversarial')]
    public function testAdversarialInputScansFast(string $in): void
    {
        $t = microtime(true);
        (new Normalizer())->scan($in);
        self::assertLessThan(0.25, microtime(true) - $t);
    }

    public function testInputIsCutAtTheCapWithoutSplittingACharacter(): void
    {
        $out = (new Normalizer())->normalize(str_repeat('é', 5000));

        self::assertSame(str_repeat('é', 4096), $out);
        self::assertSame(1, preg_match('//u', $out));
    }

    public function testShortInputIsNotCut(): void
    {
        self::assertSame(4096, mb_strlen((new Normalizer())->normalize(str_repeat('x', 4096))));
    }

    public function testDeterministic(): void
    {
        $n = new Normalizer();
        $s = 'a@b.com 10.0.0.1 42 http://x.y/z';
        self::assertSame($n->normalize($s), $n->normalize($s));
    }

    public function testScanReportsWhetherPlaceholderApplied(): void
    {
        $n = new Normalizer();
        self::assertSame(['text' => 'a b', 'changed' => false], $n->scan("a \n b"));
        self::assertSame(['text' => 'x <N>', 'changed' => true], $n->scan('x 5'));
    }

    public function testInvalidUtf8Throws(): void
    {
        $this->expectException(NormalizationException::class);
        (new Normalizer())->normalize("bad \xB1\x31 bytes");
    }
}
