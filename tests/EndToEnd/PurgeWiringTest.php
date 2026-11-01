<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Watcher\Level;
use Watcher\Operate\RunOptions;
use Watcher\RunOutcome;
use Watcher\Source\LogSource;
use Watcher\State\Purgeable;
use Watcher\State\StateStore;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\SpyLogger;

/** US5: Watcher::run() purges a Purgeable store after the due gate and before the lock; a purge fault changes nothing (T025). */
final class PurgeWiringTest extends TestCase
{
    private static function source(): LogSource
    {
        $events = Harness::events(Level::Warning, 'Alpha failed in a unique way', 5, 'Alpha'); // first runs: Warning only records a baseline (FR-035)

        return new FakeLogSource(Harness::totalsOf($events), $events);
    }

    public function testADueRunPurgesOnceBeforeTheLock(): void
    {
        $h = new Harness();
        $h->state = $store = new PurgeableStore($h->state);

        $r = $h->watcher(self::source())->run();

        self::assertSame(RunOutcome::Baselined, $r->outcome);
        self::assertSame(1, $store->purges);
        self::assertSame('purge', $store->events[0]);
        self::assertSame('acquire', $store->events[1]);
    }

    public function testANotDueTriggerNeverPurges(): void
    {
        $h = new Harness(['schedule' => ['run_at' => '06:00']]);
        $h->clock->advance('+3 hours');
        $h->state = $store = new PurgeableStore($h->state);

        $r = $h->watcher(self::source())->run();

        self::assertSame(RunOutcome::SkippedNotDue, $r->outcome);
        self::assertSame(0, $store->purges);
        self::assertSame([], $store->events);
    }

    public function testADryRunNeverPurges(): void
    {
        $h = new Harness();
        $h->state = $store = new PurgeableStore($h->state);

        $h->watcher(self::source(), RunOptions::dryRun(), static function (string $s): void {
        })->run();

        self::assertSame(0, $store->purges);
    }

    public function testAThrowingPurgeNeverChangesTheResult(): void
    {
        $h = new Harness();
        $h->hostLog = $log = new SpyLogger();
        $h->state = $store = new PurgeableStore($h->state);
        $store->failPurge = true;

        $r = $h->watcher(self::source())->run();

        self::assertSame(RunOutcome::Baselined, $r->outcome);
        self::assertSame(0, $r->exitCode());
        self::assertSame('warning', $log->records[0][0]);
        self::assertStringNotContainsString('disk exploded', $log->records[0][1]);
    }
}

final class PurgeableStore implements StateStore, Purgeable
{
    public int $purges = 0;
    public bool $failPurge = false;
    /** @var list<string> */
    public array $events = [];

    public function __construct(private readonly StateStore $inner)
    {
    }

    public function purge(int $maxFiles = 5000, float $maxSeconds = 2.0): int
    {
        ++$this->purges;
        $this->events[] = 'purge';
        if ($this->failPurge) {
            throw new RuntimeException('disk exploded');
        }

        return 0;
    }

    public function get(string $key): ?array
    {
        return $this->inner->get($key);
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->inner->put($key, $value, $ttlSeconds);
    }

    public function acquire(string $key, string $owner, int $ttlSeconds): bool
    {
        $this->events[] = 'acquire';

        return $this->inner->acquire($key, $owner, $ttlSeconds);
    }

    public function release(string $key, string $owner): void
    {
        $this->inner->release($key, $owner);
    }

    public function increment(string $key, float $by, int $ttlSeconds): float
    {
        return $this->inner->increment($key, $by, $ttlSeconds);
    }
}
