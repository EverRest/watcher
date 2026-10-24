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

    /** @param list<string> $watch */
    private static function group(int $count, string $fp = 'fp1', Level $level = Level::Error, array $watch = []): RedactedGroup
    {
        return new RedactedGroup($fp, 't', $level, null, [], $count, null, GroupStatusLabel::New, $watch, new DateTimeImmutable(), new DateTimeImmutable());
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

    public function testAdviceFieldsAreStoredAndAnOldEntryWithoutThemStillLoads(): void
    {
        $cache = new VerdictCache($this->store, 'svc', 100);
        $cache->put('fp1', 'interesting', 5, new Finding(['fp1'], FindingKind::Bug, Severity::Error, 'T', 'E', 'C', 'A', 0.5, false, 'look at X', ['fp1 too']));
        $f = ($cache->get('fp1') ?? [])['finding'] ?? [];
        self::assertSame('look at X', $f['how_to_check'] ?? null);
        self::assertSame(['fp1 too'], $f['related_checks'] ?? null);

        // written by an older version: no advice keys
        $this->store->put(Keys::verdict('svc', 'old'), ['triage' => 'interesting', 'atCount' => 3, 'finding' => [
            'fingerprints' => ['old'], 'kind' => 'bug', 'severity' => 'error', 'title' => 'T', 'evidence' => 'E',
            'suspected_cause' => 'C', 'suggested_action' => 'A', 'confidence' => 0.5, 'escalated' => false,
        ]], 100);
        $old = $cache->get('old');
        self::assertSame('T', $old['finding']['title'] ?? null);
        self::assertTrue($cache->isReusable(self::group(3, 'old', Level::Error), 2.0));
    }

    public function testReusableBelowGrowthFactorTimesAtCount(): void
    {
        $cache = new VerdictCache($this->store, 'svc', 100);
        $cache->put('fp1', 'known', 10, null);

        $w = Level::Warning;
        self::assertTrue($cache->isReusable(self::group(10, 'fp1', $w), 2.0));
        self::assertTrue($cache->isReusable(self::group(19, 'fp1', $w), 2.0));
        self::assertFalse($cache->isReusable(self::group(20, 'fp1', $w), 2.0), 'at the threshold it is re-triaged');
        self::assertFalse($cache->isReusable(self::group(50, 'fp1', $w), 2.0));
        self::assertFalse($cache->isReusable(self::group(1, 'other', $w), 2.0), 'no verdict, not reusable');
    }

    public function testServicesDoNotShareVerdicts(): void
    {
        (new VerdictCache($this->store, 'a', 100))->put('fp1', 'noise', 1, null);

        self::assertNull((new VerdictCache($this->store, 'b', 100))->get('fp1'));
    }

    public function testNoiseOfAnErrorGroupIsNeverStored(): void
    {
        $cache = new VerdictCache($this->store, 'svc', 1000);
        foreach (['noise', 'known'] as $v) {
            $cache->storeTriage(self::group(5, 'fp1', Level::Error), $v);
            $cache->storeTriage(self::group(5, 'fp2', Level::Critical), $v);
        }

        self::assertSame([], $this->store->ttls());
    }

    public function testNoiseOfAWatchRuleGroupIsNeverStored(): void
    {
        $cache = new VerdictCache($this->store, 'svc', 1000);
        $cache->storeTriage(self::group(5, 'fp1', Level::Warning, ['payments']), 'noise');

        self::assertSame([], $this->store->ttls());
    }

    public function testNoiseOfAWarningGroupIsStoredWithTheShortTtl(): void
    {
        $cache = new VerdictCache($this->store, 'svc', 1000, 777);
        $cache->storeTriage(self::group(5, 'fp1', Level::Warning), 'noise');

        self::assertSame([Keys::verdict('svc', 'fp1') => 777], $this->store->ttls());
        self::assertSame('noise', $cache->get('fp1')['triage'] ?? null);
    }

    public function testDefaultShortTtlIsThreeDays(): void
    {
        (new VerdictCache($this->store, 'svc', 1000))->storeTriage(self::group(5, 'fp1', Level::Warning), 'known');

        self::assertSame([Keys::verdict('svc', 'fp1') => 3 * 86400], $this->store->ttls());
    }

    public function testInterestingVerdictsKeepFullRetention(): void
    {
        $cache = new VerdictCache($this->store, 'svc', 1000, 777);
        $cache->put('fp1', 'interesting', 5, new Finding(['fp1'], FindingKind::Bug, Severity::Error, 'T', 'E', 'C', 'A', 0.5));
        $cache->put('fp2', 'interesting', 5, null);

        self::assertSame([Keys::verdict('svc', 'fp1') => 1000, Keys::verdict('svc', 'fp2') => 1000], $this->store->ttls());
    }

    public function testAnEarlierStoredNoiseIsNotReusedForAnErrorOrWatchGroup(): void
    {
        $cache = new VerdictCache($this->store, 'svc', 1000);
        $cache->put('fp1', 'noise', 10, null);
        $cache->put('fp2', 'interesting', 10, new Finding(['fp2'], FindingKind::Bug, Severity::Error, 't', 'e', 'c', 'a', 0.9));
        $cache->put('fp3', 'interesting', 10, null); // analysed, nothing reported: not settled for an error group (F1)

        self::assertFalse($cache->isReusable(self::group(10, 'fp1', Level::Error), 2.0));
        self::assertFalse($cache->isReusable(self::group(10, 'fp1', Level::Warning, ['w']), 2.0));
        self::assertTrue($cache->isReusable(self::group(10, 'fp1', Level::Warning), 2.0));
        self::assertTrue($cache->isReusable(self::group(10, 'fp2', Level::Error), 2.0));
        self::assertFalse($cache->isReusable(self::group(10, 'fp3', Level::Error), 2.0));
        self::assertTrue($cache->isReusable(self::group(10, 'fp3', Level::Warning), 2.0));
    }

    /** @return array<string, array{mixed}> */
    public static function corrupt(): array
    {
        $ok = ['triage' => 'noise', 'atCount' => 5];

        return [
            'string count' => [['atCount' => '5'] + $ok],
            'float count' => [['atCount' => 5.5] + $ok],
            'negative' => [['atCount' => -1] + $ok],
            'huge' => [['atCount' => 10_000_000] + $ok],
            'bad verdict' => [['triage' => 'ignore'] + $ok],
            'verdict not string' => [['triage' => ['noise']] + $ok],
            'missing count' => [['triage' => 'noise']],
            'missing verdict' => [['atCount' => 5]],
            'empty' => [[]],
        ];
    }

    /** @param array<mixed> $value */
    #[\PHPUnit\Framework\Attributes\DataProvider('corrupt')]
    public function testCorruptStoredValueIsNoVerdict(array $value): void
    {
        $this->store->put(Keys::verdict('svc', 'fp1'), $value, 100);
        $cache = new VerdictCache($this->store, 'svc', 100);

        self::assertNull($cache->get('fp1'));
        self::assertFalse($cache->isReusable(self::group(1, 'fp1', Level::Warning), 2.0));
    }
}
