<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Incident;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Incident\KnownIssue;
use Watcher\Incident\KnownIssues;
use Watcher\Incident\KnownReferences;
use Watcher\Level;
use Watcher\Reduce\GroupStatus;
use Watcher\Reduce\ProblemGroup;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class KnownIssuesTest extends TestCase
{
    private function group(string $fp, int $count): ProblemGroup
    {
        $t = new DateTimeImmutable('2026-10-08T12:00:00Z');

        return new ProblemGroup($fp, 't', Level::Error, 'E', [], $count, $t, $t, 10, GroupStatus::Expected);
    }

    public function testBelowThresholdStaysKnownLine(): void
    {
        $clock = new \Watcher\Tests\Fakes\FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00Z'));
        $store = new InMemoryStateStore($clock);
        $refs = new KnownReferences($store, 'svc', 86400);
        $refs->touch('abc123def456', 100, $clock->now());
        $ki = new KnownIssues([new KnownIssue('abc123', 'ABC-1', false, null)], 3.0);
        $r = $ki->partition([$this->group('abc123def4567890', 200)], $clock->now(), $refs);
        self::assertSame([], $r->kept);
        self::assertCount(1, $r->lines);
        self::assertSame([], $r->grown);
    }

    public function testGrownStaysCandidate(): void
    {
        $clock = new \Watcher\Tests\Fakes\FrozenClock(new DateTimeImmutable('2026-10-08T06:00:00Z'));
        $store = new InMemoryStateStore($clock);
        $refs = new KnownReferences($store, 'svc', 86400);
        $store->put(\Watcher\State\Keys::known('svc', 'abc123def4567890'), ['ref' => 10, 'seen' => '2026-10-08'], 86400);
        $ki = new KnownIssues([new KnownIssue('abc123', 'ABC-1', false, null)], 3.0);
        $r = $ki->partition([$this->group('abc123def4567890', 31)], $clock->now(), $refs);
        self::assertCount(1, $r->kept);
        self::assertTrue(isset($r->grown['abc123def4567890']));
    }
}
