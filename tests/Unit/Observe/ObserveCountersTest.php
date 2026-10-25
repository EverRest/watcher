<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use PHPUnit\Framework\TestCase;
use Watcher\Observe\ObserveCounters;
use Watcher\State\Keys;
use Watcher\State\StateStore;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\HookedStateStore;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class ObserveCountersTest extends TestCase
{
    private const RETENTION = 86400;

    private function counters(?StateStore $store = null): ObserveCounters
    {
        return new ObserveCounters($store ?? new InMemoryStateStore(new FrozenClock(new \DateTimeImmutable('2026-10-24T12:00:00+00:00'))), 'svc@prod', self::RETENTION);
    }

    public function testKeysHaveTheirOwnKinds(): void
    {
        self::assertSame('obsruns:svc%40prod:reported', Keys::observeRuns('svc@prod', 'reported'));
        self::assertSame('obsfail:svc%40prod:http', Keys::observeSinkFailures('svc@prod', 'http'));
        self::assertSame('obsdeliv:svc%40prod:slack', Keys::observeDeliveryFailures('svc@prod', 'slack'));
        self::assertSame('obsseen:svc%40prod:http', Keys::observeSeen('svc@prod', 'http'));
        self::assertSame(self::RETENTION, Keys::ttl(Keys::OBSRUNS, self::RETENTION, 900));
    }

    public function testCountsRunsSinkFailuresAndDeliveryFailures(): void
    {
        $c = $this->counters();
        $c->countRun('reported');
        $c->countRun('reported');
        $c->countRun('failed');
        $c->countSinkFailures(['http' => 1, 'file' => 1]);
        $c->countDelivery('slack');

        $s = $c->snapshot();

        self::assertSame(2.0, $s['runs']['reported']);
        self::assertSame(1.0, $s['runs']['failed']);
        self::assertSame(['file' => 1.0, 'http' => 1.0], array_filter($s['sink_failures']));
        self::assertSame(1.0, $s['delivery_failures']['slack']);
    }

    public function testEveryWriteCarriesTheStateRetentionAsExpiry(): void
    {
        $ttls = [];
        $store = new HookedStateStore(new InMemoryStateStore(new FrozenClock(new \DateTimeImmutable('2026-10-24T12:00:00+00:00'))), static function (string $m, string $key, int $ttl) use (&$ttls): void {
            if ($m !== 'get') {
                $ttls[$key] = $ttl;
            }
        });
        $c = $this->counters($store);

        $c->countRun('quiet');
        $c->countSinkFailures(['http' => 1]);
        $c->countDelivery('discord');
        $c->markClean(['http' => false]);

        self::assertNotSame([], $ttls);
        self::assertSame([self::RETENTION], array_values(array_unique($ttls)));
    }

    public function testAFailingStoreNeverThrowsAndReadsAsUnknown(): void
    {
        $store = new HookedStateStore(new InMemoryStateStore(new FrozenClock(new \DateTimeImmutable('2026-10-24T12:00:00+00:00'))), static function (): void {
            throw new \RuntimeException('down');
        });
        $c = $this->counters($store);

        $c->countRun('reported');
        $c->countSinkFailures(['http' => 1]);
        $c->countDelivery('slack');
        $c->markClean(['http' => true]);

        self::assertSame(['runs' => [], 'sink_failures' => [], 'delivery_failures' => []], $c->snapshot());
        self::assertSame([], $c->unseenSinkFailures());
    }

    public function testAFailureStaysUnseenUntilACleanFlushOfThatSink(): void
    {
        $c = $this->counters();
        self::assertSame([], $c->unseenSinkFailures());

        $c->countSinkFailures(['http' => 1]);
        self::assertSame(['http'], $c->unseenSinkFailures());

        $c->markClean(['http' => false]); // flush where http was enabled and clean
        self::assertSame([], $c->unseenSinkFailures());

        $c->countSinkFailures(['http' => 1, 'file' => 1]);
        $c->markClean(['http' => true, 'file' => false]); // http failed again, file clean
        self::assertSame(['http'], $c->unseenSinkFailures());
    }

    public function testUnknownLabelValuesAreNeverStored(): void
    {
        $store = new InMemoryStateStore(new FrozenClock(new \DateTimeImmutable('2026-10-24T12:00:00+00:00')));
        $c = $this->counters($store);

        $c->countRun('<script>');
        $c->countSinkFailures(['syslog' => 1]);
        $c->countDelivery('fax');

        self::assertSame(['runs' => [], 'sink_failures' => [], 'delivery_failures' => []], array_map('array_filter', $c->snapshot()));
    }
}
