<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

final class IncidentConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function base(): array
    {
        return [
            'service' => 'svc',
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki']],
            'analysis' => [
                'api_key' => 'k',
                'models' => ['triage' => 'm1', 'analyze' => 'm2', 'escalate' => 'm3'],
                'prices' => ['m1' => [1, 5, 0.1, 1.25], 'm2' => [3, 15, 0.3, 3.75], 'm3' => [5, 25, 0.5, 6.25]],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/w']],
            'notify' => ['digest' => ['driver' => 'slack', 'webhook' => 'https://hooks.example/x']],
        ];
    }

    public function testKnownIssueFingerprintPattern(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('known_issues.entries[0].fingerprint must be 12 to 64 hex characters');
        WatchConfig::fromArray(self::base() + ['known_issues' => ['entries' => [['fingerprint' => 'NOTHEX', 'ticket' => 'A-1']]]]);
    }

    public function testKnownIssueTicketInvalid(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('known_issues.entries[0].ticket invalid');
        WatchConfig::fromArray(self::base() + ['known_issues' => ['entries' => [['fingerprint' => 'abc123def456', 'ticket' => 'bad ticket']]]]);
    }
}
