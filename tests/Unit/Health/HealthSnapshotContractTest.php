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

/**
 * Contract with the host that writes the snapshot (m-seq, command health:check-services): the fixture is the shape that command
 * stores under m-seq:health, one JSON object of service => {success, version, checked_at}. If that shape changes on the host,
 * this test fails here first.
 */
final class HealthSnapshotContractTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/health/m-seq-snapshot.json';

    public function testTheHostSnapshotIsReadIntoOneCopyPerService(): void
    {
        $source = new SnapshotRedis();
        $source->value = (string) file_get_contents(self::FIXTURE);
        $state = new TtlSpyStateStore(new InMemoryStateStore(new SystemClock()));

        $code = $this->check($source, $state)->run(new DateTimeImmutable('2026-11-01T10:00:00Z'));

        self::assertSame(0, $code, 'a DOWN service is a state, not a failed run');
        self::assertSame(
            ['success' => true, 'version' => '1.4.2', 'checked_at' => '2026-11-01T09:00:00+00:00'],
            $state->get(Keys::healthcheck('watcher', 'm-auth')),
        );
        self::assertSame(
            ['success' => false, 'version' => null, 'checked_at' => '2026-11-01T09:00:00+00:00'],
            $state->get(Keys::healthcheck('watcher', 'm-core')),
        );
        self::assertSame(
            ['success' => true, 'version' => null, 'checked_at' => '2026-11-01T09:00:00+00:00'],
            $state->get(Keys::healthcheck('watcher', 'm-participant')),
            'a service without a version is stored as no version',
        );
    }

    private function check(SnapshotRedis $source, TtlSpyStateStore $state): HealthCheck
    {
        return new HealthCheck(
            source: $source,
            sourceKey: 'm-seq:health',
            state: $state,
            notifier: new RecordingNotifier(),
            to: Destination::none(),
            renderer: new NoticeRenderer(),
            log: new NullLogger(),
            service: 'watcher',
            environment: 'test11',
            ttlSeconds: 14 * 86400,
            notifyTransitions: false,
            summary: null,
            staleAfterHours: null,
            problemsHourly: false,
        );
    }
}
