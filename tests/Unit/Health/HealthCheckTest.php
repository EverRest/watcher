<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Health;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Watcher\Health\HealthCheck;
use Watcher\Notify\Destination;
use Watcher\Notify\NoticeRenderer;
use Watcher\Schedule\SystemClock;
use Watcher\State\Keys;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\RecordingNotifier;
use Watcher\Tests\Fakes\SnapshotRedis;
use Watcher\Tests\Fakes\TtlSpyStateStore;

final class HealthCheckTest extends TestCase
{
    private const TTL = 14 * 86400;

    private const KEY = 'orders';

    private SnapshotRedis $source;

    private RecordingNotifier $notifier;

    private TtlSpyStateStore $state;

    protected function setUp(): void
    {
        $this->source = new SnapshotRedis();
        $this->notifier = new RecordingNotifier();
        $this->state = new TtlSpyStateStore(new InMemoryStateStore(new SystemClock()));
    }

    public function testFirstRunStoresTheCopySilently(): void
    {
        $this->publish('1.2.0');

        self::assertSame(0, $this->runAt('2026-10-29T10:00:00Z'));

        self::assertSame([], $this->notifier->sent);
        self::assertSame(
            ['success' => true, 'version' => '1.2.0', 'checked_at' => '2026-10-29T10:00:00+00:00'],
            $this->state->get(Keys::healthcheck('watcher', self::KEY)),
        );
    }

    public function testVersionChangeAlertsOnceAndNotAgainForTheSameVersion(): void
    {
        $this->publish('1.2.0');
        $this->runAt('2026-10-29T10:00:00Z');

        $this->publish('1.3.0');
        self::assertSame(0, $this->runAt('2026-10-29T11:00:00Z'));
        self::assertCount(1, $this->notifier->sent);
        self::assertStringStartsWith('🔔 VERSION CHANGED orders', $this->notifier->sent[0]);
        self::assertStringContainsString('1.2.0 → 1.3.0', $this->notifier->sent[0]);

        $this->runAt('2026-10-29T12:00:00Z');
        self::assertCount(1, $this->notifier->sent, 'the same version on the next hour is not a change');
    }

    /** @dataProvider unknownVersions */
    public function testUnknownVersionsNeverAlert(?string $unknown): void
    {
        $this->publish('1.2.0');
        $this->runAt('2026-10-29T10:00:00Z');

        $this->publish($unknown);
        $this->runAt('2026-10-29T11:00:00Z');
        $this->publish('1.2.0');
        $this->runAt('2026-10-29T12:00:00Z');

        self::assertSame([], $this->notifier->sent);
    }

    /** @return iterable<string, array{?string}> */
    public static function unknownVersions(): iterable
    {
        yield 'null' => [null];
        yield 'Version not defined' => ['Version not defined'];
        yield 'empty' => [''];
    }

    public function testUpDownTransitionsAreOffByDefault(): void
    {
        $this->publish('1.2.0', success: true);
        $this->runAt('2026-10-29T10:00:00Z');
        $this->publish('1.2.0', success: false);
        $this->runAt('2026-10-29T11:00:00Z');

        self::assertSame([], $this->notifier->sent);
    }

    public function testTransitionAlertsOnceWhenTheFlagIsOn(): void
    {
        $check = $this->check(transitions: true);
        $this->publish('1.2.0', success: true);
        $check->run(new DateTimeImmutable('2026-10-29T10:00:00Z'));

        $this->publish('1.2.0', success: false);
        $check->run(new DateTimeImmutable('2026-10-29T11:00:00Z'));
        $check->run(new DateTimeImmutable('2026-10-29T12:00:00Z'));

        self::assertCount(1, $this->notifier->sent, 'a service that stays down is not alerted every hour');
        self::assertStringContainsString('DOWN orders', $this->notifier->sent[0]);
    }

    public function testEveryHourlyWriteRefreshesTheTtl(): void
    {
        $this->publish('1.2.0');
        $this->runAt('2026-10-29T10:00:00Z');
        $this->runAt('2026-10-29T11:00:00Z');

        self::assertSame(self::TTL, $this->state->ttls[Keys::healthcheck('watcher', self::KEY)]);
    }

    public function testRedisReadFailureReturnsOneAndDoesNotThrow(): void
    {
        $this->source->fail = true;

        self::assertSame(1, $this->runAt('2026-10-29T10:00:00Z'));
        self::assertSame([], $this->notifier->sent);
        self::assertSame([], $this->state->ttls);
    }

    public function testMissingSnapshotReturnsOne(): void
    {
        self::assertSame(1, $this->runAt('2026-10-29T10:00:00Z'));
    }

    public function testASecondRunInTheSameHourDoesNothing(): void
    {
        $this->publish('1.2.0');
        $this->runAt('2026-10-29T10:00:00Z');

        $this->publish('1.3.0');
        self::assertSame(0, $this->runAt('2026-10-29T10:30:00Z'), 'the hour is already checked');
        self::assertSame([], $this->notifier->sent);

        $this->runAt('2026-10-29T11:00:00Z');
        self::assertCount(1, $this->notifier->sent);
    }

    public function testAFailedSendKeepsTheOldCopySoTheNextHourRetries(): void
    {
        $this->publish('1.2.0');
        $this->runAt('2026-10-29T10:00:00Z');

        $this->publish('1.3.0');
        $this->notifier->fail = true;
        self::assertSame(1, $this->runAt('2026-10-29T11:00:00Z'));
        $stored = $this->state->get(Keys::healthcheck('watcher', self::KEY));
        self::assertSame('1.2.0', $stored['version'] ?? null);

        $this->notifier->fail = false;
        self::assertSame(0, $this->runAt('2026-10-29T12:00:00Z'));
        self::assertCount(1, $this->notifier->sent);
        self::assertStringContainsString('1.2.0 → 1.3.0', $this->notifier->sent[0]);
    }

    public function testAMalformedEntryIsSkippedAndTheOthersAreStored(): void
    {
        $this->source->value = json_encode([
            'broken' => ['version' => '1.0.0'],
            self::KEY => ['success' => true, 'version' => '1.2.0', 'checked_at' => '2026-10-29T10:00:00+00:00'],
        ], JSON_THROW_ON_ERROR);

        self::assertSame(0, $this->runAt('2026-10-29T10:00:00Z'));
        self::assertNull($this->state->get(Keys::healthcheck('watcher', 'broken')));
        self::assertNotNull($this->state->get(Keys::healthcheck('watcher', self::KEY)));
    }

    /** Two watchers with their own service names sharing one store and one host: each must see the change, not just the first. */
    public function testTwoWatchersOnTheSameHostEachSeeTheChange(): void
    {
        $this->publish('1.2.0');
        $this->check(service: 'billing')->run(new DateTimeImmutable('2026-10-29T10:00:00Z'));
        $this->check(service: 'orders')->run(new DateTimeImmutable('2026-10-29T10:00:00Z'));

        $this->publish('1.3.0');
        $this->check(service: 'billing')->run(new DateTimeImmutable('2026-10-29T11:00:00Z'));
        $this->check(service: 'orders')->run(new DateTimeImmutable('2026-10-29T11:00:00Z'));

        self::assertCount(2, $this->notifier->sent);
    }

    private function runAt(string $at): int
    {
        return $this->check()->run(new DateTimeImmutable($at));
    }

    /** Publishes the host snapshot for the next run: one service, `orders`. */
    private function publish(?string $version, bool $success = true): void
    {
        $this->source->value = json_encode([
            self::KEY => ['success' => $success, 'version' => $version, 'checked_at' => '2026-10-29T10:00:00+00:00'],
        ], JSON_THROW_ON_ERROR);
    }

    private function check(bool $transitions = false, string $service = 'watcher'): HealthCheck
    {
        return new HealthCheck(
            source: $this->source,
            sourceKey: 'm-seq:health',
            state: $this->state,
            notifier: $this->notifier,
            to: Destination::none(),
            renderer: new NoticeRenderer(),
            log: new NullLogger(),
            service: $service,
            environment: 'test11',
            ttlSeconds: self::TTL,
            notifyTransitions: $transitions,
        );
    }
}
