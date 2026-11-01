<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Probe;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Probe\ProbeResult;
use Watcher\Probe\SecretExpiryProbe;
use Watcher\Tests\Fakes\FrozenClock;

/** Date math only: a secret is valid through its `expires` date (UTC), and no secret value is ever read. */
final class SecretExpiryProbeTest extends TestCase
{
    private const NOW = '2026-10-28 12:00:00';

    /**
     * @param list<array{name: string, expires: string, owner: string}> $secrets
     *
     * @return list<ProbeResult>
     */
    private function check(array $secrets): array
    {
        return (new SecretExpiryProbe($secrets, 30, 7, new FrozenClock(new DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))))->check();
    }

    public function testASecretExpiringInFiveDaysFails(): void
    {
        $results = $this->check([['name' => 'PAYMENT_API_KEY', 'expires' => '2026-11-02', 'owner' => 'team-payments']]);

        self::assertSame(ProbeResult::FAIL, $results[0]->status);
        self::assertSame('secret:PAYMENT_API_KEY', $results[0]->subject);
        self::assertSame(5, $results[0]->metric);
        self::assertStringContainsString('owner team-payments', $results[0]->message);
    }

    public function testThresholdsAndAlreadyExpired(): void
    {
        $results = $this->check([
            ['name' => 'A', 'expires' => '2026-11-15', 'owner' => 'o'], // 18 days: warn
            ['name' => 'B', 'expires' => '2026-12-30', 'owner' => 'o'], // 63 days: ok
            ['name' => 'C', 'expires' => '2026-10-27', 'owner' => 'o'], // yesterday: fail
        ]);

        self::assertSame([ProbeResult::WARN, ProbeResult::OK, ProbeResult::FAIL], array_map(static fn (ProbeResult $r): string => $r->status, $results));
    }

    public function testTheExpiryDateItselfIsStillValid(): void
    {
        $results = $this->check([['name' => 'A', 'expires' => '2026-10-28', 'owner' => 'o']]);

        self::assertSame(ProbeResult::FAIL, $results[0]->status);
        self::assertSame(0, $results[0]->metric);
    }
}
