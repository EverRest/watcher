<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Mcp;

use DateTimeImmutable;
use Watcher\Mcp\LastReport;
use Watcher\Mcp\OutputGuard;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

/** Shared setup for the inspect-tool tests. */
final class InspectFixtures
{
    public const SERVICE = 'orders-api';

    public static function clock(): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable('2026-10-25T09:00:00Z'));
    }

    public static function store(): InMemoryStateStore
    {
        return new InMemoryStateStore(self::clock());
    }

    public static function guard(): OutputGuard
    {
        return new OutputGuard(new Redactor(new Normalizer()));
    }

    /** @param array<string, mixed> $over */
    public static function putReport(InMemoryStateStore $s, array $over = []): void
    {
        $s->put(LastReport::key(self::SERVICE), $over + [
            'v' => 1, 'windowStart' => '2026-10-24T06:00:00+00:00', 'windowEnd' => '2026-10-25T06:00:00+00:00', 'outcome' => 'reported', 'at' => '2026-10-25T06:03:10+00:00',
            'counts' => ['events' => 1200, 'groups' => 40, 'candidates' => 7, 'findings' => 3],
            'findings' => [
                ['fp' => 'a3f9c01d22be', 'severity' => 'error', 'title' => 'Payment gateway timeouts'],
                ['fp' => 'b3f9c01d22be', 'severity' => 'warn', 'title' => 'Slow queries'],
                ['fp' => 'c3f9c01d22be', 'severity' => 'info', 'title' => 'Cache misses'],
            ],
        ], 3600);
    }

    /** @param array<string, mixed> $finding */
    public static function putVerdict(InMemoryStateStore $s, string $fp, array $finding, int $atCount = 120): void
    {
        $s->put(Keys::verdict(self::SERVICE, $fp), ['triage' => 'interesting', 'atCount' => $atCount, 'finding' => $finding, 'tpl' => 'SECRET TEMPLATE <HOST> failed', 'frames' => ['x.php:3']], 3600);
        $s->put(Keys::reported(self::SERVICE, $fp), ['lastReportedAt' => '2026-10-25T06:03:10+02:00', 'atCount' => $atCount, 'extra' => 'NOPE'], 3600);
    }

    /** @return array<string, mixed> */
    public static function finding(): array
    {
        return [
            'fingerprints' => ['a3f9c01d22be'], 'kind' => 'bug', 'severity' => 'error', 'title' => 'Payment gateway timeouts', 'evidence' => 'Timeouts began after the 05:40 deploy.',
            'suspected_cause' => 'Connection pool too small.', 'suggested_action' => 'Raise the pool size.', 'confidence' => 0.8, 'escalated' => true,
            'how_to_check' => 'Compare the pool metric before and after.', 'related_checks' => ['b3f9c01d22be', 'payments', 'not allowed one'],
        ];
    }
}
