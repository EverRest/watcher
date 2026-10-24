<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Operate\StateInspector;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;
use Watcher\Tests\Fakes\RecordingStateStore;

final class StateInspectorTest extends TestCase
{
    private RecordingStateStore $store;

    private StateInspector $inspector;

    protected function setUp(): void
    {
        $this->store = new RecordingStateStore(new InMemoryStateStore(new FrozenClock(new DateTimeImmutable('2026-10-24T09:00:00Z'))));
        $this->inspector = new StateInspector($this->store, new Redactor(new Normalizer()), 'orders', ['sk-ant-SECRET']);
    }

    public function testPrintsTheHeartbeatAsJson(): void
    {
        $this->store->put('hb:orders', ['outcome' => 'quiet', 'costUsd' => 0.25, 'windowEnd' => '2026-10-24T06:00:00+00:00'], 60);

        $text = (string) $this->inspector->render('heartbeat');

        $decoded = json_decode($text, true);
        self::assertIsArray($decoded);
        self::assertSame('quiet', $decoded['outcome']);
        self::assertSame(0.25, $decoded['costUsd']);
    }

    /** @return array<string, array{string, string}> logical key => the store key it resolves to */
    public static function resolves(): array
    {
        return [
            'heartbeat' => ['heartbeat', 'hb:orders'],
            'last snapshot' => ['last-snapshot', 'snap:orders:last'],
            'snapshot of a window end' => ['snapshot:2026-10-24T06:00:00Z', 'snap:orders:2026-10-24T06%3A00%3A00Z'],
            'run record' => ['run:2026-10-24T06:00:00Z', 'run:orders:2026-10-24T06%3A00%3A00Z'],
        ];
    }

    #[DataProvider('resolves')]
    public function testResolvesOnlyServiceScopedLogicalKeys(string $spec, string $storeKey): void
    {
        $this->store->put($storeKey, ['x' => 1], 60);

        self::assertNotNull($this->inspector->render($spec));
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'raw redis key' => ['watcher:hb:orders'],
            'other service' => ['heartbeat:other'],
            'lock' => ['lock:orders:2026-10-24T06:00:00Z'],
            'glob' => ['*'],
            'path' => ['../../etc/passwd'],
            'snapshot with a malformed end' => ['snapshot:yesterday'],
            'run without an end' => ['run'],
            'empty' => [''],
        ];
    }

    #[DataProvider('refused')]
    public function testRefusesAnythingElseWithoutTouchingTheStore(string $spec): void
    {
        try {
            $this->inspector->render($spec);
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->store->calls);
        }
    }

    public function testMissingEntryIsNull(): void
    {
        self::assertNull($this->inspector->render('heartbeat'));
    }

    public function testNeverWritesAnything(): void
    {
        $this->store->put('hb:orders', ['outcome' => 'quiet'], 60);
        $this->store->calls = [];
        $this->inspector->render('heartbeat');

        self::assertSame([], $this->store->writes());
    }

    public function testStringsAreRedactedAndConfiguredSecretsMasked(): void
    {
        $this->store->put('hb:orders', [
            'reason' => 'failed for user jane.doe@example.com from 10.1.2.3',
            'detail' => 'key was sk-ant-SECRET-and-more',
            'nested' => ['deep' => ['mail' => 'ops@example.org']],
        ], 60);

        $text = (string) $this->inspector->render('heartbeat');

        foreach (['jane.doe@example.com', '10.1.2.3', 'sk-ant-SECRET', 'ops@example.org'] as $raw) {
            self::assertStringNotContainsString($raw, $text);
        }
    }

    public function testLongStringsAreCutTo200Characters(): void
    {
        $this->store->put('hb:orders', ['reason' => str_repeat('abcdefghij ', 100), 'short' => 'ok'], 60);

        $decoded = json_decode((string) $this->inspector->render('heartbeat'), true);

        self::assertIsArray($decoded);
        self::assertLessThanOrEqual(200 + 40, mb_strlen($decoded['reason']));
        self::assertStringContainsString('truncated', $decoded['reason']);
        self::assertStringStartsWith('abcdefghij', $decoded['reason']);
        self::assertSame('ok', $decoded['short']);
    }

    public function testSnapshotGroupFingerprintsAreKeptReadable(): void
    {
        $this->store->put('snap:orders:last', ['end' => '2026-10-24T06:00:00Z', 'groups' => ['0123456789ab' => 4]], 60);

        $decoded = json_decode((string) $this->inspector->render('last-snapshot'), true);

        self::assertIsArray($decoded);
        self::assertSame(['0123456789ab' => 4], $decoded['groups']);
    }
}
