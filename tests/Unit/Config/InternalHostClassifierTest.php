<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\SecureUrl;

/** One SSRF host classifier for every opt-in caller (metrics, Sentry, OpenAPI source): literal addresses only, names are not resolved. */
final class InternalHostClassifierTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function internalV4(): iterable
    {
        $hosts = [
            '127.0.0.1', '127.255.255.254', '0.0.0.0', '0.1.2.3', '10.0.0.1', '10.255.255.255', '172.16.0.1', '172.31.255.255', '192.168.0.1',
            '169.254.169.254', '169.254.0.1', '100.64.0.1', '100.127.255.255', '224.0.0.1', '239.255.255.255', '240.0.0.1', '255.255.255.255',
            // every inet_aton spelling of loopback, link-local/metadata, 10/8, 192.168/16 and 100.64/10
            '2130706433', '0x7f000001', '0177.0.0.1', '127.1', '0x7f.1', '0177.0x0.0.01', '0x7f.0.0.0x1',
            '2852039166', '0xa9fea9fe', '0251.0376.0251.0376', '169.254.43518',
            '167772161', '0xa000001', '012.0.0.1', '3232235777', '1681915905', '0',
            // one trailing dot is the same host
            '127.0.0.1.', '169.254.169.254.',
        ];
        foreach ($hosts as $h) {
            yield $h => [$h];
        }
    }

    #[DataProvider('internalV4')]
    public function testInternalV4IsRefused(string $host): void
    {
        self::assertTrue(SecureUrl::internalHost($host), $host);
    }

    /** @return iterable<string, array{string}> */
    public static function publicV4(): iterable
    {
        foreach (['8.8.8.8', '1.1.1.1', '11.0.0.1', '126.255.255.255', '128.0.0.1', '172.15.255.255', '172.32.0.0', '192.167.255.255', '192.169.0.1', '169.253.255.255', '169.255.0.1',
            '100.63.255.255', '100.128.0.0', '223.255.255.255', '134744072', '0x8080808', '1.2.3.4.5', '256.1.1.1', '08.0.0.1'] as $h) {
            yield $h => [$h];
        }
    }

    #[DataProvider('publicV4')]
    public function testPublicV4AndNamesPass(string $host): void
    {
        self::assertFalse(SecureUrl::internalHost($host), $host);
    }

    /** @return iterable<string, array{string}> */
    public static function names(): iterable
    {
        foreach (['example.com', 'prom.example.com', 'localhost.evil.com', 'evil-localhost.com', 'metadata.google.internal.evil.com', 'a.b.c.d.e', '0x7f.evil.com', 'xn--80ak6aa92e.com'] as $h) {
            yield $h => [$h];
        }
    }

    #[DataProvider('names')]
    public function testOrdinaryNamesPass(string $host): void
    {
        self::assertFalse(SecureUrl::internalHost($host), $host);
    }

    /** @return iterable<string, array{string}> */
    public static function internalNames(): iterable
    {
        foreach (['localhost', 'LOCALHOST', 'localhost.', 'a.localhost', 'a.b.localhost.', 'metadata.google.internal', 'METADATA.GOOGLE.INTERNAL.', 'metadata'] as $h) {
            yield $h => [$h];
        }
    }

    #[DataProvider('internalNames')]
    public function testLocalhostAndMetadataNamesAreRefused(string $host): void
    {
        self::assertTrue(SecureUrl::internalHost($host), $host);
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        foreach (['', '.', '..', 'a..b', '.a', 'a.b..', '127.0.0.1..', '1..1', 'host%zone', 'fe80::1%eth0', '[fe80::1%25eth0]', '[fe80::1%eth0]', '[::1', '[not:ipv6]', '1:2', ':::', '[12345::1]', '[::1]x', 'a:b', '[1.2.3.4]'] as $h) {
            yield $h === '' ? '(empty)' : $h => [$h];
        }
    }

    /** Empty labels, a %zone and an unparsable ':' host cannot be classified, so they are refused. */
    #[DataProvider('malformed')]
    public function testUnclassifiableHostsFailClosed(string $host): void
    {
        self::assertTrue(SecureUrl::internalHost($host), $host);
        self::assertTrue(SecureUrl::internalHost($host, true, true), $host . ' (even with every allowance)');
    }

    /** @return iterable<string, array{string}> */
    public static function internalV6(): iterable
    {
        $hosts = [
            '::1', '[::1]', '::', '::2', '::7f00:1', '::10.0.0.1', 'fc00::1', 'fdff:ffff::1', 'fe80::1', 'febf::1', 'fec0::1', 'feff::1', 'ff02::1', 'ff00::',
            '64:ff9b::1', '64:ff9b::a00:1', '64:ff9b::808:808', '64:ff9b:1::1', '64:ff9b:1:ffff:ffff:ffff:ffff:ffff',
            '::ffff:127.0.0.1', '::ffff:7f00:1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '[::ffff:a9fe:a9fe]', '::ffff:100.64.0.1', '::ffff:0.0.0.0', '::ffff:192.168.1.1', '::ffff:172.16.0.1',
            '2002:7f00:1::1', '2002:a9fe:a9fe::1', '2002:a00:1::', '2002:c0a8:101::1', '2002:6440:1::1', '2002:0:0::1',
            '2001::1', '2001:0:4136:e378:8000:63bf:3fff:fdd2', '2001:0:ffff:ffff::1',
        ];
        foreach ($hosts as $h) {
            yield $h => [$h];
        }
    }

    #[DataProvider('internalV6')]
    public function testInternalV6IsRefused(string $host): void
    {
        self::assertTrue(SecureUrl::internalHost($host), $host);
    }

    /** @return iterable<string, array{string}> */
    public static function publicV6(): iterable
    {
        foreach (['2001:db8::1', '2001:4860:4860::8888', '2606:4700::1111', '2001:1::1', '2001:200::1', '64:ff9a::1', '64:ff9c::1', '64:ff9b:2::1', '64:ff9b:0:1::1', '::ffff:8.8.8.8', '[::ffff:808:808]',
            '2002:808:808::1', '2002:b00:1::', 'fbff::1', 'fe7f::1', '1::', '4000::1'] as $h) {
            yield $h => [$h];
        }
    }

    #[DataProvider('publicV6')]
    public function testPublicV6Passes(string $host): void
    {
        self::assertFalse(SecureUrl::internalHost($host), $host);
    }

    /** @return iterable<string, array{string}> */
    public static function loopback(): iterable
    {
        foreach (['127.0.0.1', '2130706433', '0x7f000001', '127.1', 'localhost', 'a.localhost', '::1', '[::1]', '::ffff:127.0.0.1', '::ffff:7f00:1', '2002:7f00:1::1'] as $h) {
            yield $h => [$h];
        }
    }

    #[DataProvider('loopback')]
    public function testLoopbackIsAllowedOnlyOnRequestAndNothingElseWithIt(string $host): void
    {
        self::assertTrue(SecureUrl::internalHost($host), $host);
        self::assertFalse(SecureUrl::internalHost($host, allowLoopback: true), $host);
    }

    public function testAllowLoopbackDoesNotWidenAnythingElse(): void
    {
        foreach (['0.0.0.0', '::', '10.0.0.1', '169.254.169.254', '100.64.0.1', 'fc00::1', 'fe80::1', '64:ff9b::1', '2001::1', 'metadata.google.internal', '::ffff:10.0.0.1'] as $h) {
            self::assertTrue(SecureUrl::internalHost($h, allowLoopback: true), $h);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function rfc1918(): iterable
    {
        foreach (['10.0.0.1', '10.255.255.255', '172.16.0.1', '172.31.255.255', '192.168.1.1', '167772161', '0xa000001', '012.0.0.1', '3232235777', '::ffff:10.0.0.1', '::ffff:c0a8:101', '2002:a00:1::', '2002:c0a8:101::1'] as $h) {
            yield $h => [$h];
        }
    }

    /** An internal Prometheus or Grafana is the normal case for metrics: RFC 1918 can be allowed, nothing else private. */
    #[DataProvider('rfc1918')]
    public function testRfc1918IsAllowedOnlyOnRequest(string $host): void
    {
        self::assertTrue(SecureUrl::internalHost($host), $host);
        self::assertFalse(SecureUrl::internalHost($host, allowPrivate: true), $host);
    }

    public function testAllowPrivateDoesNotWidenAnythingElse(): void
    {
        foreach (['127.0.0.1', 'localhost', '::1', '0.0.0.0', '169.254.169.254', '100.64.0.1', '::ffff:100.64.0.1', 'fc00::1', 'fd12::1', 'fe80::1', 'fec0::1', 'ff02::1', '64:ff9b:1::1', '2001::1', 'metadata'] as $h) {
            self::assertTrue(SecureUrl::internalHost($h, allowPrivate: true), $h);
        }
    }

    public function testExistingCallersAreUnchangedWithoutTheOptIn(): void
    {
        foreach (['https://169.254.169.254/', 'https://localhost/', 'https://[::1]/', 'https://10.0.0.1/', 'https://100.64.0.1/', 'https://0x7f.1/'] as $url) {
            self::assertNull(SecureUrl::problem($url), $url);
        }
        self::assertNull(SecureUrl::problem('http://10.1.2.3:3100', true));
        self::assertNull(SecureUrl::problem('http://127.0.0.1:3100', true));
    }

    public function testTheOptInRefusesAnInternalHostWithAFixedPhraseThatNeverEchoesTheUrl(): void
    {
        $url = 'https://169.254.169.254/latest/meta-data?token=SECRET';
        $problem = SecureUrl::problem($url, false, true);

        self::assertSame('must not point at a loopback, link-local, private-network or metadata address', $problem);
        self::assertStringNotContainsString('169.254', (string) $problem);
        self::assertStringNotContainsString('SECRET', (string) $problem);
        foreach (['https://[::1]/', 'https://10.0.0.1/', 'https://100.64.0.1/', 'https://[64:ff9b::1]/', 'https://0x7f.1/', 'https://localhost./'] as $u) {
            self::assertNotNull(SecureUrl::problem($u, false, true), $u);
        }
    }

    public function testTheOptInKeepsLoopbackAndPrivateAvailableWhenAskedFor(): void
    {
        self::assertNotNull(SecureUrl::problem('http://127.0.0.1:9090', false, true), 'http loopback still needs allow_insecure');
        self::assertNull(SecureUrl::problem('http://127.0.0.1:9090', true, true));
        self::assertNull(SecureUrl::problem('https://[::1]:9090', true, true));
        self::assertNotNull(SecureUrl::problem('http://169.254.169.254/', true, true), 'link-local stays refused even with allow_insecure');
        self::assertNotNull(SecureUrl::problem('http://10.0.0.5:9090', true, true), 'private is refused unless allowPrivate');
        self::assertNull(SecureUrl::problem('http://10.0.0.5:9090', true, true, true));
        self::assertNull(SecureUrl::problem('https://prom.example.com/', false, true));
    }

    public function testTheOptInKeepsTheBaseRules(): void
    {
        self::assertNotNull(SecureUrl::problem('http://prom.example.com', true, true));
        self::assertNotNull(SecureUrl::problem('https://user:pw@prom.example.com', false, true));
        self::assertNotNull(SecureUrl::problem('https:///x', false, true));
        self::assertNotNull(SecureUrl::problem('https://[fe80::1%25eth0]/', false, true));
    }

    /** @return iterable<string, array{string}> */
    public static function clusterNames(): iterable
    {
        foreach (['kubernetes.default.svc', 'KUBERNETES.DEFAULT.SVC.', 'a.svc', 'grafana.monitoring.svc.cluster.local', 'x.cluster.local', 'db.internal', 'ip-10-0-0-1.ec2.internal'] as $h) {
            yield $h => [$h];
        }
    }

    /** Cluster-local names count as private: refused by default, admitted by allowPrivate. */
    #[DataProvider('clusterNames')]
    public function testClusterLocalNamesAreRefusedUnlessPrivateIsAllowed(string $host): void
    {
        self::assertTrue(SecureUrl::internalHost($host), $host);
        self::assertTrue(SecureUrl::internalHost($host, true), $host . ' (loopback allowance is not private)');
        self::assertFalse(SecureUrl::internalHost($host, false, true), $host);
        self::assertNotNull(SecureUrl::problem("https://{$host}/", false, true), $host);
    }

    public function testMetadataNamesStayRefusedEvenWhenPrivateIsAllowed(): void
    {
        foreach (['metadata.google.internal', 'METADATA.GOOGLE.INTERNAL.', 'metadata', 'instance-data', 'instance-data.', 'Instance-Data'] as $h) {
            self::assertTrue(SecureUrl::internalHost($h, true, true), $h);
        }
        self::assertFalse(SecureUrl::internalHost('svc.example.com'));
        self::assertFalse(SecureUrl::internalHost('internal.example.com'));
        self::assertFalse(SecureUrl::internalHost('mysvc'));
    }

    /** @return iterable<string, array{string}> */
    public static function nonAscii(): iterable
    {
        foreach (["127\u{3002}0\u{3002}0\u{3002}1", "127\u{FF0E}0\u{FF0E}0\u{FF0E}1", "\u{FF10}x7f.1", "\u{2460}\u{2461}\u{2466}.0.0.1", "caf\u{E9}.example", "127.0.0.1\n", "127.0.0.1\t", 'a b', "a\0b", "\xff\xfe"] as $h) {
            yield bin2hex($h) => [$h];
        }
    }

    #[DataProvider('nonAscii')]
    public function testNonAsciiAndControlHostsFailClosedInTheClassifierItself(string $host): void
    {
        self::assertTrue(SecureUrl::internalHost($host, true, true));
    }

    /** @return iterable<string, array{string}> */
    public static function reserved(): iterable
    {
        foreach (['198.18.0.1', '198.19.255.255', '192.0.0.1', '192.0.0.255', '192.0.2.1', '198.51.100.7', '203.0.113.9',
            '3325256705', '0xc6120001', '::ffff:198.18.0.1', '2002:c612:1::1',
            // SIIT ::ffff:0:0:0/96 carries the IPv4 in the last 32 bits
            '::ffff:0:a9fe:a9fe', '::ffff:0:c612:1', '::ffff:0:0:0'] as $h) {
            yield $h => [$h];
        }
    }

    /** Documentation, benchmarking and IETF-assignment ranges are never a legitimate target and no allowance admits them. */
    #[DataProvider('reserved')]
    public function testReservedRangesAndSiitAreRefusedWhateverIsAllowed(string $host): void
    {
        self::assertTrue(SecureUrl::internalHost($host), $host);
        self::assertTrue(SecureUrl::internalHost($host, true, true), $host);
    }

    public function testSiitDecodesTheEmbeddedAddressAndHonoursTheAllowances(): void
    {
        foreach (['::ffff:0:7f00:1', '::ffff:0:127.0.0.1'] as $h) {
            self::assertTrue(SecureUrl::internalHost($h), $h);
            self::assertFalse(SecureUrl::internalHost($h, allowLoopback: true), $h);
        }
        self::assertTrue(SecureUrl::internalHost('::ffff:0:a00:1'));
        self::assertFalse(SecureUrl::internalHost('::ffff:0:a00:1', allowPrivate: true));
    }

    public function testNeighboursOfTheReservedRangesPass(): void
    {
        foreach (['198.17.255.255', '198.20.0.0', '192.0.1.1', '192.0.3.1', '198.51.99.1', '198.51.101.1', '203.0.112.1', '203.0.114.1', '::ffff:0:808:808', '::ffff:1:808:808'] as $h) {
            self::assertFalse(SecureUrl::internalHost($h), $h);
        }
    }
}
