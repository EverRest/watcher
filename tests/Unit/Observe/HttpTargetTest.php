<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Observe\HttpTarget;

final class HttpTargetTest extends TestCase
{
    private const SHAPE = 'observe.http.url must be an https URL with a host';
    private const NOT_ALLOWED = 'observe.http.url host is not allowed';

    /** @param list<string> $allowed */
    private static function parse(string $url, array $allowed = [], bool $insecure = false, bool $private = false, ?string $source = null): HttpTarget
    {
        return HttpTarget::parse($url, $allowed, $insecure, $private, $source);
    }

    public function testAcceptsHttpsAndExposesUrlOnlyThroughUrl(): void
    {
        $t = self::parse('https://logs.example.com/ingest?token=SECRET');
        self::assertSame('https://logs.example.com/ingest?token=SECRET', $t->url());
        self::assertStringNotContainsString('SECRET', print_r($t, true));
        self::assertFalse((new \ReflectionClass($t))->hasMethod('__toString'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejected(): iterable
    {
        yield 'no scheme' => ['logs.example.com/x', self::SHAPE];
        yield 'no host' => ['https:///x', self::SHAPE];
        yield 'ftp' => ['ftp://logs.example.com/x', self::SHAPE];
        yield 'http' => ['http://logs.example.com/x', self::SHAPE];
        yield 'userinfo' => ['https://u:p@logs.example.com/x', 'observe.http.url must not contain credentials'];
        yield 'user only' => ['https://tok@logs.example.com/x', 'observe.http.url must not contain credentials'];
        yield 'metadata v4' => ['https://169.254.169.254/latest', self::NOT_ALLOWED];
        yield 'metadata v6' => ['https://[fd00:ec2::254]/x', self::NOT_ALLOWED];
        yield 'metadata v6 mapped' => ['https://[::ffff:169.254.169.254]/x', self::NOT_ALLOWED];
        yield 'metadata gcp' => ['https://metadata.google.internal/x', self::NOT_ALLOWED];
        yield 'localhost' => ['https://localhost/x', self::NOT_ALLOWED];
        yield 'loopback' => ['https://127.0.0.1/x', self::NOT_ALLOWED];
        yield 'loopback v6' => ['https://[::1]/x', self::NOT_ALLOWED];
        yield '10/8' => ['https://10.1.2.3/x', self::NOT_ALLOWED];
        yield '172.16/12' => ['https://172.31.0.1/x', self::NOT_ALLOWED];
        yield '192.168/16' => ['https://192.168.1.1/x', self::NOT_ALLOWED];
        yield 'link-local' => ['https://169.254.1.1/x', self::NOT_ALLOWED];
        yield 'ula' => ['https://[fc00::1]/x', self::NOT_ALLOWED];
        yield 'link-local v6' => ['https://[fe80::1]/x', self::NOT_ALLOWED];
        yield 'decimal loopback' => ['https://2130706433/x', self::NOT_ALLOWED];
        yield 'hex metadata' => ['https://0xa9fea9fe/x', self::NOT_ALLOWED];
        yield 'dotted hex loopback' => ['https://0x7f.0.0.1/x', self::NOT_ALLOWED];
        yield 'octal loopback' => ['https://0177.0.0.1/x', self::NOT_ALLOWED];
        yield 'short loopback' => ['https://127.1/x', self::NOT_ALLOWED];
        yield 'three part private' => ['https://10.1.258/x', self::NOT_ALLOWED];
        yield 'percent host' => ['https://127%2e0.0.1/x', self::NOT_ALLOWED];
        yield 'cgnat' => ['https://100.64.0.1/x', self::NOT_ALLOWED];
        yield 'zero net' => ['https://0.1.2.3/x', self::NOT_ALLOWED];
        yield 'multicast' => ['https://224.0.0.1/x', self::NOT_ALLOWED];
        yield 'reserved' => ['https://240.0.0.1/x', self::NOT_ALLOWED];
        yield 'unspecified v6' => ['https://[::]/x', self::NOT_ALLOWED];
        yield 'multicast v6' => ['https://[ff02::1]/x', self::NOT_ALLOWED];
        yield 'nat64 loopback' => ['https://[64:ff9b::7f00:1]/x', self::NOT_ALLOWED];
        yield '6to4 private' => ['https://[2002:0a00:0001::1]/x', self::NOT_ALLOWED];
        yield 'mapped private' => ['https://[::ffff:10.0.0.1]/x', self::NOT_ALLOWED];
        yield 'nat64 metadata' => ['https://[64:ff9b::a9fe:a9fe]/x', self::NOT_ALLOWED];
    }

    #[DataProvider('rejected')]
    public function testRejectsWithFixedMessageThatNeverEchoesTheUrl(string $url, string $message): void
    {
        try {
            self::parse($url);
            self::fail('expected InvalidConfig');
        } catch (InvalidConfig $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public function testPrivateAddressesAllowedWithFlagButMetadataNever(): void
    {
        foreach (['https://localhost/x', 'https://127.0.0.1/x', 'https://10.1.2.3/x', 'https://172.16.0.1/x', 'https://192.168.1.1/x', 'https://[::1]/x'] as $url) {
            self::assertSame($url, self::parse($url, private: true)->url());
        }
        foreach (['https://[fc00::1]/x', 'https://169.254.1.1/x', 'https://169.254.170.2/x', 'https://100.100.100.200/x', 'https://169.254.169.254/x', 'https://[fd00:ec2::254]/x', 'https://metadata.google.internal/x', 'https://METADATA.GOOGLE.INTERNAL./x'] as $url) {
            try {
                self::parse($url, private: true);
                self::fail('metadata must stay refused');
            } catch (InvalidConfig $e) {
                self::assertSame(self::NOT_ALLOWED, $e->getMessage());
            }
        }
    }

    public function testNumericFormsFollowThePrivateFlagButMetadataNever(): void
    {
        foreach (['https://2130706433/x', 'https://0x7f.0.0.1/x', 'https://127.1/x', 'https://[::ffff:10.0.0.1]/x'] as $url) {
            self::assertSame($url, self::parse($url, private: true)->url());
        }
        foreach (['https://100.64.0.1/x', 'https://[64:ff9b::7f00:1]/x', 'https://0xa9fea9fe/x', 'https://2852039166/x', 'https://[64:ff9b::a9fe:a9fe]/x', 'https://[2002:a9fe:a9fe::1]/x'] as $url) {
            try {
                self::parse($url, private: true);
                self::fail('metadata must stay refused');
            } catch (InvalidConfig $e) {
                self::assertSame(self::NOT_ALLOWED, $e->getMessage());
            }
        }
    }

    public function testPublicLiteralsAreFine(): void
    {
        self::assertSame('https://134744072/x', self::parse('https://134744072/x')->url()); // 8.8.8.8
        self::assertSame('https://[2002:0808:0808::1]/x', self::parse('https://[2002:0808:0808::1]/x')->url());
        // DNS names are never resolved (owner decision O5): this host is accepted although it resolves to the metadata IP.
        self::assertSame('https://169.254.169.254.nip.io/', self::parse('https://169.254.169.254.nip.io/')->url());
        self::assertSame('https://8.8.8.8/x', self::parse('https://8.8.8.8/x')->url());
        self::assertSame('https://172.32.0.1/x', self::parse('https://172.32.0.1/x')->url());
    }

    public function testRefusesSerializeAndExportWithoutLeakingTheUrl(): void
    {
        $t = self::parse('https://logs.example.com/ingest?token=SECRET');
        try {
            serialize($t);
            self::fail('expected LogicException');
        } catch (\LogicException $e) {
            self::assertStringNotContainsString('SECRET', $e->getMessage());
        }
        self::assertStringNotContainsString('SECRET', var_export($t, true));
        self::assertStringNotContainsString('SECRET', print_r($t, true));
        self::assertTrue((new \ReflectionParameter([HttpTarget::class, 'parse'], 'url'))->getAttributes(\SensitiveParameter::class) !== []);
    }

    public function testHttpOnlyWithAllowInsecure(): void
    {
        self::assertSame('http://collector.obs.svc/x', self::parse('http://collector.obs.svc/x', insecure: true, private: true)->url());
        self::assertSame('http://10.0.0.5/x', self::parse('http://10.0.0.5/x', insecure: true, private: true)->url());
    }

    public function testPlainHttpToAPublicHostIsNeverAccepted(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage(self::SHAPE);
        self::parse('http://logs.example.com/x', insecure: true);
    }

    public function testPlainHttpWithoutTheFlagIsRefused(): void
    {
        $this->expectException(InvalidConfig::class);
        self::parse('http://collector.obs.svc/x');
    }

    public function testAllowedHostsIsExactLowercaseMatch(): void
    {
        self::assertSame('https://Logs.Example.com/x', self::parse('https://Logs.Example.com/x', ['logs.example.com'])->url());
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('observe.http.url host is not in observe.http.allowed_hosts');
        self::parse('https://sub.logs.example.com/x', ['logs.example.com']);
    }

    public function testRefusesTheLogSourceHost(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('observe.http.url must not point at the log source');
        self::parse('https://Loki.Example.com:3100/x', source: 'loki.example.com');
    }
}
