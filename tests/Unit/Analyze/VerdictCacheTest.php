<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Analyze;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Analyze\Finding;
use Watcher\Analyze\FindingKind;
use Watcher\Analyze\Severity;
use Watcher\Analyze\VerdictCache;
use Watcher\Level;
use Watcher\Privacy\GroupStatusLabel;
use Watcher\Privacy\RedactedGroup;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class VerdictCacheTest extends TestCase
{
    private InMemoryStateStore $store;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-23T06:00:00+00:00'));
        $this->store = new InMemoryStateStore($this->clock);
    }

    private static function group(int $count, string $fp = 'fp1'): RedactedGroup
    {
        return new RedactedGroup($fp, 't', Level::Error, null, [], $count, null, GroupStatusLabel::New, [], new DateTimeImmutable(), new DateTimeImmutable());
    }

    public function testGetMissingIsNull(): void
    {
        self::assertNull((new VerdictCache($this->store, 'svc', 100))->get('fp1'));
    }

    public function testPutStoresUnderTheVerdictKeyWithTtl(): void
    {
        $cache = new VerdictCache($this->store, 'svc', 100);
        $cache->put('fp1', 'noise', 10, null);

        self::assertSame('noise', $this->store->get(Keys::verdict('svc', 'fp1'))['triage'] ?? null);
        self::assertSame('noise', $cache->get('fp1')['triage'] ?? null);
        self::assertSame(10, $cache->get('fp1')['atCount']);

        $this->clock->advance('+101 seconds');
        self::assertNull($cache->get('fp1'), 'expires after the retention TTL');
    }

    public function testFindingIsStored(): void
    {
        $cache = new VerdictCache($this->store, 'svc', 100);
        $cache->put('fp1', 'interesting', 5, new Finding(['fp1'], FindingKind::Bug, Severity::Error, 'T', 'E', 'C', 'A', 0.5));

        self::assertSame('T', ($cache->get('fp1') ?? [])['finding']['title'] ?? null);
    }

    public function testReusableBelowGrowthFactorTimesAtCount(): void
    {
        $cache = new VerdictCache($this->store, 'svc', 100);
        $cache->put('fp1', 'known', 10, null);

        self::assertTrue($cache->isReusable(self::group(10), 2.0));
        self::assertTrue($cache->isReusable(self::group(19), 2.0));
        self::assertFalse($cache->isReusable(self::group(20), 2.0), 'at the threshold it is re-triaged');
        self::assertFalse($cache->isReusable(self::group(50), 2.0));
        self::assertFalse($cache->isReusable(self::group(1, 'other'), 2.0), 'no verdict, not reusable');
    }

    public function testServicesDoNotShareVerdicts(): void
    {
        (new VerdictCache($this->store, 'a', 100))->put('fp1', 'noise', 1, null);

        self::assertNull((new VerdictCache($this->store, 'b', 100))->get('fp1'));
    }
}
