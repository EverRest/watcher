<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Probe;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Probe\CertificateProbe;
use Watcher\Probe\ProbeResult;
use Watcher\Tests\Fakes\FrozenClock;

/** Probe thresholds on real certificates generated with `openssl req -x509`; the clock is frozen so the day count is exact. */
final class CertificateProbeTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }

    /** @return array<string, mixed> the parsed certificate `openssl_x509_parse` returns, valid for $days from now */
    private function certificate(int $days): array
    {
        $dir = sys_get_temp_dir() . '/probe-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->dirs[] = $dir;
        $cert = $dir . '/cert.pem';
        $key = $dir . '/key.pem';
        exec(sprintf(
            'openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -subj /CN=api.example.test -days %d -keyout %s -out %s 2>/dev/null',
            $days,
            escapeshellarg($key),
            escapeshellarg($cert),
        ), $out, $code);
        self::assertSame(0, $code, 'openssl req must generate the test certificate');
        $parsed = openssl_x509_parse((string) file_get_contents($cert));
        self::assertIsArray($parsed);

        return $parsed;
    }

    /** @param array<string, mixed>|null $cert */
    private function probe(?array $cert, ?DateTimeImmutable $now = null, int $warn = 30, int $fail = 7): CertificateProbe
    {
        return new CertificateProbe(['api.example.test'], $warn, $fail, new FrozenClock($now ?? new DateTimeImmutable()), static fn (): ?array => $cert);
    }

    /** @param array<string, mixed>|null $cert */
    private function statusOf(?array $cert, ?DateTimeImmutable $now = null): string
    {
        $results = $this->probe($cert, $now)->check();
        self::assertCount(1, $results);

        return $results[0]->status;
    }

    public function testOneDayLeftFails(): void
    {
        self::assertSame(ProbeResult::FAIL, $this->statusOf($this->certificate(1)));
    }

    public function testTwentyDaysLeftWarns(): void
    {
        $result = $this->probe($this->certificate(20))->check()[0];

        self::assertSame(ProbeResult::WARN, $result->status);
        self::assertSame('certificate:api.example.test', $result->subject);
        self::assertContains($result->metric, [19, 20], 'openssl rounds notAfter, so the day count may read one higher');
        self::assertStringNotContainsString('BEGIN', $result->message);
    }

    public function testSixtyDaysLeftIsOk(): void
    {
        self::assertSame(ProbeResult::OK, $this->statusOf($this->certificate(60)));
    }

    public function testAnExpiredCertificateFailsWhateverTheThresholds(): void
    {
        $cert = $this->certificate(1);
        $afterExpiry = (new DateTimeImmutable('@' . $cert['validTo_time_t']))->modify('+2 days');

        self::assertSame(ProbeResult::FAIL, $this->statusOf($cert, $afterExpiry));
    }

    public function testAnUnreadableHostIsUnknownNeverFail(): void
    {
        $result = $this->probe(null)->check()[0];

        self::assertSame(ProbeResult::UNKNOWN, $result->status);
        self::assertSame('certificate api.example.test could not be read', $result->message);
    }
}
