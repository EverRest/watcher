<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\Fingerprinter;
use Watcher\Reduce\Normalizer;
use Watcher\Source\LogEvent;

/**
 * Golden fingerprints over Normalizer rule classes (P-H1). A change here means bump {@see Fingerprinter::FPV}.
 */
final class FingerprintCorpusTest extends TestCase
{
    private function fp(LogEvent $e, ?Normalizer $normalizer = null): string
    {
        return (new Fingerprinter($normalizer ?? new Normalizer()))->of($e);
    }

    private function ev(string $msg, Level $l = Level::Error, ?string $type = 'E', string $frame = 'app.php:1'): LogEvent
    {
        return new LogEvent(new DateTimeImmutable('2026-01-01'), $l, $msg, $type, [$frame], [], []);
    }

    /** @return array<string, array{0: string, 1: string, 2?: Level, 3?: ?string, 4?: string}> */
    public static function corpus(): array
    {
        return [
            'payment name (digits after word two)' => ['Payment declined for Anna Kowalski', '18c37570955d', Level::Error, 'E', 'a.php:1'],
            'lock timeout' => ['Lock wait timeout exceeded order=4411', 'ddc8fcc19766', Level::Error, null, 'db.php:9'],
            'connection critical' => ['Connection refused to redis now', '4cdc728c3b6e', Level::Critical, 'RuntimeException', 'b.php:2'],
            'warning no type' => ['boom', 'c161a677331e', Level::Warning, null, ''],
            'email' => ['mail john@example.com failed', '414545e10bda'],
            'url undeclared host' => ['calling http://payments/v1/charge?id=7 now', 'f0921076cae5'],
            'ipv4' => ['from 10.0.12.7 refused', 'd4c0724cf504'],
            'uuid' => ['order 123e4567-e89b-12d3-a456-426614174000 gone', 'deae9d4b7eb0'],
            'jwt' => ['auth eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl bad', 'b2f2c532daa3'],
            'pan spaces' => ['card 4111 1111 1111 1111 declined', '4795c9346a0e'],
            'absolute path tail' => ['failed at /var/www/html/app/src/Service.php line 9', '008575c54013'],
            'known token prefix' => ['key sk-ant-api03-abcdefghijklmnopqrstuvwxyz1234567890 leaked', 'd974e2612c6c'],
            'password key' => ['login failed password=SuperSecret123!', '6cdcab29f2e9'],
            'relative query values' => ['GET /api/users?token=abc123&sig=deadbeef failed', 'e0052583f40c'],
            'bare fqdn host' => ['see https://john-smith.example.com/me', '88b4dc4e51c3'],
            'digit masking' => ['took 1532 ms for 3 rows', '249546b79514'],
            'long double-quoted' => ['bad "' . str_repeat('x', 33) . '" here', '341b55b86cc0'],
            'home user path' => ['cannot read /home/alice/data/file.json', '8df5903a0f5b'],
            'ssh public key' => ['bad ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQC7 failed', '5b1226196a70'],
            'authorization bearer' => ['Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig', 'f6bfc378e83c'],
            'hex id 8+' => ['sha deadbeef12 bad', 'c987bc963d81'],
            'ipv6' => ['from 2001:db8::1 refused', 'd4c0724cf504'],
            'percent-encoded email in url' => ['GET https://api.example.org/x?e=a%40b.com failed', 'f0dd62fb5f63'],
            'sqlstate digits' => ['SQLSTATE[40001]: deadlock', '9e670437a9d0'],
        ];
    }

    #[DataProvider('corpus')]
    public function testPinnedFingerprint(string $message, string $expected, Level $level = Level::Error, ?string $type = 'E', string $frame = 'app.php:1'): void
    {
        self::assertSame($expected, $this->fp($this->ev($message, $level, $type, $frame)));
    }

    public function testExtraPatternPlaceholder(): void
    {
        $n = new Normalizer([], [], extraPatterns: ['cust' => '/CUST-\d{6}/']);
        self::assertSame('ff403b9d8536', $this->fp($this->ev('order CUST-482913 failed'), $n));
    }

    public function testDeclaredDependencyUrl(): void
    {
        $n = new Normalizer(['core'], ['vendor.net']);
        self::assertSame('a608fb244b5c', $this->fp($this->ev('calling http://core.vendor.net/x now'), $n));
    }

    public function testFingerprintVersionConstantIsPositive(): void
    {
        self::assertGreaterThan(0, Fingerprinter::FPV);
    }
}
