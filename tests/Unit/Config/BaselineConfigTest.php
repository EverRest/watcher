<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\InvalidConfig;
use Watcher\Config\WatchConfig;

final class BaselineConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private static function cfg(array $patch = []): array
    {
        return array_replace_recursive([
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
        ], $patch);
    }

    public function testDefaults(): void
    {
        $c = WatchConfig::fromArray(self::cfg());

        self::assertSame('previous', $c->baseline['mode']);
        self::assertSame('P7D', $c->baseline['max_age']);
        self::assertSame(7 * 86400, $c->baseline['max_age_seconds']);
        self::assertSame([], $c->baseline['exclude']);
        self::assertNull($c->baseline['since']);
        self::assertSame(['similarity' => 0.5, 'message_cap' => 50000, 'backend_patterns' => false, 'memory_budget_mb' => 32], $c->grouping);
    }

    public function testWindowsDefaultPerMode(): void
    {
        self::assertSame(7, WatchConfig::fromArray(self::cfg(['baseline' => ['mode' => 'rolling']]))->baseline['windows']);
        self::assertSame(4, WatchConfig::fromArray(self::cfg(['baseline' => ['mode' => 'weekday']]))->baseline['windows']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'mode' => [['baseline' => ['mode' => 'monthly']], 'baseline.mode must be one of previous, rolling, weekday'];
        yield 'rolling windows' => [['baseline' => ['mode' => 'rolling', 'windows' => 1]], 'baseline.windows must be an integer >= 2'];
        yield 'weekday windows' => [['baseline' => ['mode' => 'weekday', 'windows' => 2]], 'baseline.windows must be an integer >= 3'];
        yield 'windows type' => [['baseline' => ['mode' => 'rolling', 'windows' => 'x']], 'baseline.windows must be an integer >= 2'];
        yield 'max_age' => [['baseline' => ['max_age' => 'soon']], 'baseline.max_age must be an ISO-8601 duration'];
        yield 'exclude shape' => [['baseline' => ['exclude' => ['25.12.2026']]], 'baseline.exclude entries must be dates like 2026-12-25'];
        yield 'exclude impossible date' => [['baseline' => ['exclude' => ['2026-02-30']]], 'baseline.exclude entries must be dates like 2026-12-25'];
        yield 'since' => [['baseline' => ['since' => 'yesterday']], 'baseline.since must be a date like 2026-10-01'];
        yield 'unknown key' => [['baseline' => ['bogus' => 1]], 'Unknown configuration key: baseline.bogus'];
        yield 'similarity zero' => [['grouping' => ['similarity' => 0]], 'grouping.similarity must be greater than 0 and at most 1'];
        yield 'similarity above one' => [['grouping' => ['similarity' => 1.1]], 'grouping.similarity must be greater than 0 and at most 1'];
        yield 'message_cap' => [['grouping' => ['message_cap' => 999]], 'grouping.message_cap must be an integer >= 1000'];
        yield 'backend_patterns' => [['grouping' => ['backend_patterns' => true]], 'grouping.backend_patterns is not available yet'];
        yield 'grouping unknown key' => [['grouping' => ['bogus' => 1]], 'Unknown configuration key: grouping.bogus'];
        yield 'rolling needs whole-day window' => [
            ['baseline' => ['mode' => 'rolling'], 'schedule' => ['window' => 'PT12H']],
            'baseline.mode rolling/weekday needs a whole-day schedule.window',
        ];
        yield 'max_age above retention' => [
            ['baseline' => ['max_age' => 'P40D']],
            'baseline.max_age exceeds state.retention',
        ];
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('invalid')]
    public function testInvalid(array $patch, string $message): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage($message);

        WatchConfig::fromArray(self::cfg($patch));
    }

    public function testRetentionTooShortForWeekdayMode(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('state.retention (1728000 s) is shorter than baseline.windows needs (2505600 s)');

        // 4 weekdays x 7 d + 1 d = 29 d; 20 d given
        WatchConfig::fromArray(self::cfg(['baseline' => ['mode' => 'weekday'], 'state' => ['retention' => 20 * 86400]]));
    }

    public function testRetentionTooShortForRolling(): void
    {
        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('state.retention');

        WatchConfig::fromArray(self::cfg(['baseline' => ['mode' => 'rolling', 'windows' => 7], 'state' => ['retention' => 7 * 86400]]));
    }

    public function testPreviousModeAcceptsAnyWindowAndShortRetention(): void
    {
        $c = WatchConfig::fromArray(self::cfg(['schedule' => ['window' => 'PT6H'], 'state' => ['retention' => 8 * 86400]]));

        self::assertSame('previous', $c->baseline['mode']);
    }

    public function testValidDatesAreKept(): void
    {
        $c = WatchConfig::fromArray(self::cfg(['baseline' => ['exclude' => ['2026-12-25'], 'since' => '2026-10-01']]));

        self::assertSame(['2026-12-25'], $c->baseline['exclude']);
        self::assertSame('2026-10-01', $c->baseline['since']);
    }
}
