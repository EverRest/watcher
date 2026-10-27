<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Operate;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Watcher\Config\WatchConfig;
use Watcher\Notify\DeliveryFailed;
use Watcher\Operate\CheckResult;
use Watcher\Operate\Doctor;
use Watcher\Operate\ModelCatalog;
use Watcher\Source\SourceUnavailable;
use Watcher\Tests\Fakes\FailingStateStore;
use Watcher\Tests\Fakes\FakeLogSource;
use Watcher\Tests\Fakes\FakeNotifier;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\InMemoryStateStore;

final class DoctorTest extends TestCase
{
    private const KEY = 'sk-ant-SECRET-KEY';
    private const HOOK = 'https://hooks.slack.test/services/T0/B0/SECRETHOOK';
    private const HOOK2 = 'https://hooks.slack.test/services/T0/B0/OTHERHOOK';

    private FrozenClock $clock;
    private InMemoryStateStore $store;
    private FakeLogSource $source;
    private FakeNotifier $notifier;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-24T09:00:00Z'));
        $this->store = new InMemoryStateStore($this->clock);
        $this->source = new FakeLogSource(['error' => 3]);
        $this->notifier = new FakeNotifier();
    }

    /**
     * @param array<string, mixed> $notify
     */
    private static function config(array $notify = []): WatchConfig
    {
        return WatchConfig::fromArray([
            'service' => 'orders',
            'enabled' => true,
            'schedule' => ['run_at' => '06:00'],
            'source' => ['driver' => 'loki', 'loki' => ['url' => 'https://loki.test', 'auth' => ['type' => 'bearer', 'token' => 'LOKITOKEN']], 'stream_tags' => ['app' => 'orders']],
            'analysis' => [
                'api_keys' => ['gemini' => self::KEY],
                'models' => ['triage' => 'm-small', 'analyze' => 'm-mid', 'escalate' => 'm-big'],
            ],
            'state' => ['driver' => 'file', 'file' => ['path' => '/tmp/x']],
            'notify' => $notify ?: ['digest' => ['webhook' => self::HOOK]],
        ]);
    }

    /**
     * @param array<string, int|string> $probe
     * @param list<string> $ids
     */
    private static function catalog(array $probe, array $ids): ModelCatalog
    {
        return new class ($probe, $ids) implements ModelCatalog {
            /**
     * @param array<string, int|string> $probe
     * @param list<string> $ids
     */
            public function __construct(private readonly array $probe, private readonly array $ids)
            {
            }

            public function probe(): array
            {
                return $this->probe;
            }

            public function modelIds(): array
            {
                return $this->ids;
            }
        };
    }

    private function doctor(?WatchConfig $config = null, ?ModelCatalog $catalog = null, ?\Watcher\State\StateStore $store = null): Doctor
    {
        return new Doctor(
            $config ?? self::config(),
            $this->source,
            $store ?? $this->store,
            $catalog ?? self::catalog(['bearer' => 200, 'x-api-key' => 200], ['m-small', 'm-mid', 'm-big']),
            $this->notifier,
            $this->clock,
        );
    }

    /** @return array<string, CheckResult> */
    private static function byName(\Watcher\Operate\CheckReport $r): array
    {
        $out = [];
        foreach ($r->results as $c) {
            $out[$c->name] = $c;
        }

        return $out;
    }

    private static function assertFailsOnly(string $name, string $reason, \Watcher\Operate\CheckReport $r): void
    {
        foreach (self::byName($r) as $n => $c) {
            if ($n === $name) {
                self::assertSame('fail', $c->status, $n);
                self::assertSame($reason, $c->reason);
            } else {
                self::assertSame('pass', $c->status, "$n: {$c->reason}");
            }
        }
        self::assertFalse($r->ok());
        self::assertSame(1, $r->exitCode());
    }

    public function testAllGreen(): void
    {
        $r = $this->doctor()->run();

        self::assertSame(['config', 'source', 'provider_auth', 'models', 'state', 'notifier'], array_keys(self::byName($r)));
        self::assertTrue($r->ok());
        self::assertSame(0, $r->exitCode());
        self::assertSame([], $this->notifier->sent);
        self::assertStringContainsString('result: ok', $r->text());
    }

    public function testSourceQueriesFiveMinutesReadOnly(): void
    {
        $this->doctor()->run();

        self::assertSame([], $this->source->callsTo('lines'));
        $args = $this->source->callsTo('totals');
        self::assertCount(1, $args);
        self::assertSame(300, $args[0][0]->end->getTimestamp() - $args[0][0]->start->getTimestamp());
        self::assertSame(['app' => 'orders'], $args[0][1]->tags);
    }

    /** @return array<string, array{string, string}> */
    public static function sourceFailures(): array
    {
        return [
            'unavailable' => ['loki unreachable', 'unavailable'],
            'unauthorised' => ['loki 401 Unauthorized', 'unauthorised'],
            'forbidden' => ['datadog 403 Forbidden', 'unauthorised'],
            'http' => ['loki 502 Bad Gateway', 'HTTP 502'],
        ];
    }

    #[DataProvider('sourceFailures')]
    public function testSourceFailureIsNamedAndTheRestStillRun(string $message, string $reason): void
    {
        $this->source->failWith(new SourceUnavailable($message . ' ' . self::KEY));

        $r = $this->doctor()->run();

        self::assertFailsOnly('source', $reason, $r);
        self::assertStringNotContainsString(self::KEY, $r->text() . json_encode($r->toArray()));
    }

    public function testApiKeySchemeIsTheOneThatDecides(): void
    {
        $r = $this->doctor(catalog: self::catalog(['x-api-key' => 200, 'bearer' => 401], ['m-small', 'm-mid', 'm-big']))->run();

        self::assertSame('pass', self::byName($r)['provider_auth']->status);
    }

    public function testApiKeyRejected(): void
    {
        $r = $this->doctor(catalog: self::catalog(['x-api-key' => 403, 'bearer' => 401], []))->run();

        $c = self::byName($r)['provider_auth'];
        self::assertSame('fail', $c->status);
        self::assertSame('x-api-key rejected (HTTP 403)', $c->reason);
        self::assertSame('skip', self::byName($r)['models']->status);
    }

    public function testProviderTimeout(): void
    {
        $r = $this->doctor(catalog: self::catalog(['bearer' => 'timeout', 'x-api-key' => 'timeout'], []))->run();

        self::assertSame('timeout', self::byName($r)['provider_auth']->reason);
    }

    public function testNoCatalogSkipsProviderChecksAndStaysGreen(): void
    {
        $d = new Doctor(self::config(), $this->source, $this->store, null, $this->notifier, $this->clock);

        $r = $d->run();

        self::assertSame('skip', self::byName($r)['provider_auth']->status);
        self::assertSame('skip', self::byName($r)['models']->status);
        self::assertTrue($r->ok());
    }

    public function testUnknownModelIdsAreNamedPerStepAndDatedFormsMatch(): void
    {
        $ids = ['m-small-20251001', 'm-big'];

        $r = $this->doctor(catalog: self::catalog(['bearer' => 200, 'x-api-key' => 200], $ids))->run();

        self::assertFailsOnly('models', 'unknown model for analyze', $r);
    }

    public function testStateWriteFailure(): void
    {
        $store = new FailingStateStore($this->store);
        $store->failOn('put');

        $r = $this->doctor(store: $store)->run();

        self::assertFailsOnly('state', 'write failed', $r);
        self::assertStringNotContainsString('hunter2', $r->text() . json_encode($r->toArray()));
    }

    public function testAnInvalidStateDirectoryIsNamedInTheCheck(): void
    {
        $dir = sys_get_temp_dir() . '/watcher-doctor-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        chmod($dir, 0777);
        try {
            $r = $this->doctor(store: new \Watcher\State\FileStateStore($dir, $this->clock, 900))->run();
        } finally {
            chmod($dir, 0700);
            rmdir($dir);
        }

        $state = self::byName($r)['state'];
        self::assertSame('fail', $state->status);
        self::assertStringContainsString('state.file.path', (string) $state->reason);
        self::assertStringNotContainsString($dir, $r->text());
    }

    public function testStateInternalErrorNamesTheClassOnly(): void
    {
        $store = new FailingStateStore($this->store);
        $store->failOn('get');

        $r = $this->doctor(store: $store)->run();

        self::assertFailsOnly('state', 'internal error (RuntimeException)', $r);
    }

    public function testStateReadBackMismatchAndNonExclusiveLock(): void
    {
        $broken = new class ($this->store) implements \Watcher\State\StateStore {
            public function __construct(private readonly \Watcher\State\StateStore $inner)
            {
            }

            public function get(string $key): ?array
            {
                return null;
            }

            public function put(string $key, array $value, int $ttlSeconds): void
            {
                $this->inner->put($key, $value, $ttlSeconds);
            }

            public function acquire(string $key, string $owner, int $ttlSeconds): bool
            {
                return true;
            }

            public function release(string $key, string $owner): void
            {
            }

            public function increment(string $key, float $by, int $ttlSeconds): float
            {
                return $this->inner->increment($key, $by, $ttlSeconds);
            }
        };

        self::assertFailsOnly('state', 'read-back mismatch', $this->doctor(store: $broken)->run());
    }

    public function testStateLockNotExclusive(): void
    {
        $lax = new class ($this->store) implements \Watcher\State\StateStore {
            public function __construct(private readonly \Watcher\State\StateStore $inner)
            {
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
                return true;
            }

            public function release(string $key, string $owner): void
            {
            }

            public function increment(string $key, float $by, int $ttlSeconds): float
            {
                return $this->inner->increment($key, $by, $ttlSeconds);
            }
        };

        self::assertFailsOnly('state', 'lock not exclusive', $this->doctor(store: $lax)->run());
    }

    public function testStateUsesUniqueScratchKeysWithTtlAndLeavesRealKeys(): void
    {
        $this->store->put('run:orders:x', ['outcome' => 'quiet'], 1000);

        $this->doctor()->run();
        $this->doctor()->run();

        self::assertSame(['outcome' => 'quiet'], $this->store->get('run:orders:x'));
        foreach ($this->store->ttls() as $key => $ttl) {
            if ($key !== 'run:orders:x') {
                self::assertStringStartsWith('check:', $key);
                self::assertLessThanOrEqual(60, $ttl);
            }
        }
        self::assertGreaterThanOrEqual(4, count($this->store->ttls())); // two runs, distinct keys left to expire
    }

    public function testInvalidWebhookIsNamed(): void
    {
        $cfg = self::config(['digest' => ['webhook' => self::HOOK], 'notice' => ['webhook' => 'http://insecure.test/x']]);

        $r = $this->doctor($cfg)->run();

        self::assertFailsOnly('notifier', 'notice webhook invalid', $r);
        self::assertStringNotContainsString('insecure.test', $r->text() . json_encode($r->toArray()));
    }

    public function testNothingPostedWithoutPostTest(): void
    {
        $this->doctor(self::config(['digest' => ['webhook' => self::HOOK], 'notice' => ['webhook' => self::HOOK2]]))->run();

        self::assertSame(0, $this->notifier->attempts);
    }

    public function testPostTestSendsOneLabelledMessagePerDistinctDestination(): void
    {
        $r = $this->doctor(self::config(['digest' => ['webhook' => self::HOOK], 'notice' => ['webhook' => self::HOOK2]]))->run(postTest: true);

        self::assertTrue($r->ok());
        self::assertCount(2, $this->notifier->sent);
        self::assertSame(self::HOOK, $this->notifier->sent[0]['to']->webhook());
        self::assertSame(self::HOOK2, $this->notifier->sent[1]['to']->webhook());
        self::assertSame('watcher check: test message for orders (digest) - safe to ignore', $this->notifier->sent[0]['message']->text);
        self::assertSame('watcher check: test message for orders (notice) - safe to ignore', $this->notifier->sent[1]['message']->text);
    }

    public function testPostTestWithOneSharedWebhookPostsOnce(): void
    {
        $this->doctor()->run(postTest: true); // notice defaults to digest

        self::assertCount(1, $this->notifier->sent);
        self::assertSame('watcher check: test message for orders (digest, notice) - safe to ignore', $this->notifier->sent[0]['message']->text);
    }

    public function testPostTestFailureIsReportedWithoutTheUrl(): void
    {
        $this->notifier->failWith(new DeliveryFailed('slack 404 ' . self::HOOK));

        $r = $this->doctor()->run(postTest: true);

        self::assertFalse($r->ok());
        self::assertSame('fail', self::byName($r)['post_test']->status);
        self::assertStringNotContainsString('SECRETHOOK', $r->text() . json_encode($r->toArray()));
    }

    public function testNoPostTestCheckWithoutTheFlag(): void
    {
        self::assertArrayNotHasKey('post_test', self::byName($this->doctor()->run()));
    }

    public function testInvalidConfigSkipsEverythingElse(): void
    {
        $r = Doctor::configFailure('invalid: analysis.models.triage');

        self::assertSame(['config', 'source', 'provider_auth', 'models', 'state', 'notifier'], array_keys(self::byName($r)));
        self::assertSame('fail', self::byName($r)['config']->status);
        self::assertSame('invalid: analysis.models.triage', self::byName($r)['config']->reason);
        self::assertSame('skip', self::byName($r)['state']->status);
        self::assertSame('config invalid', self::byName($r)['state']->reason);
        self::assertSame(1, $r->exitCode());
    }

    public function testReportAndJsonCarryNoSecretOrLogContent(): void
    {
        $this->source->failWith(new SourceUnavailable('loki 500 secret log line user@example.com'));

        $r = $this->doctor()->run(postTest: true);
        $out = $r->text() . json_encode($r->toArray(), JSON_THROW_ON_ERROR);

        foreach ([self::KEY, 'SECRETHOOK', 'LOKITOKEN', 'user@example.com', 'secret log line', 'hooks.slack.test'] as $needle) {
            self::assertStringNotContainsString($needle, $out);
        }
        $json = $r->toArray();
        self::assertSame(1, $json['schema']);
        self::assertFalse($json['ok']);
        self::assertSame(['name', 'status', 'reason'], array_keys($json['checks'][0]));
    }

    public function testTwoDoctorsOnOneStoreDoNotInterfere(): void
    {
        $a = $this->doctor();
        $b = $this->doctor();

        self::assertTrue($a->run()->ok());
        self::assertTrue($b->run()->ok());
    }

    public function testHumanTextLayout(): void
    {
        $this->source->failWith(new SourceUnavailable('loki 401'));

        $text = $this->doctor()->run()->text();

        self::assertStringContainsString("source         FAIL  unauthorised\n", $text);
        self::assertStringContainsString("config         PASS\n", $text);
        self::assertStringEndsWith("result: 1 failed\n", $text);
    }

    private const TOKEN = '123456:ABCdefGHIjklMNOpqrSTUvwxYZ0123456789';
    private const DISCORD = 'https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-123';

    public function testTelegramShapeChecked(): void
    {
        $cfg = self::config([
            'digest' => ['driver' => 'telegram', 'bot_token' => 'not-a-token-SENTINEL', 'chat_id' => '-100123'],
            'notice' => ['driver' => 'discord', 'webhook' => 'https://discord.com.evil.example/api/webhooks/1/abc'],
        ]);

        $r = $this->doctor($cfg)->run();

        self::assertFailsOnly('notifier', 'digest bot_token invalid, notice webhook invalid', $r);
        self::assertSame(0, $this->notifier->attempts);
        $out = $r->text() . json_encode($r->toArray());
        self::assertStringNotContainsString('SENTINEL', $out);
        self::assertStringNotContainsString('evil.example', $out);
    }

    public function testValidTelegramAndDiscordPass(): void
    {
        $cfg = self::config([
            'digest' => ['driver' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => '@my_channel'],
            'notice' => ['driver' => 'discord', 'webhook' => self::DISCORD],
        ]);

        self::assertTrue($this->doctor($cfg)->run()->ok());
    }

    public function testPostTestOncePerDistinctDestination(): void
    {
        $tg = static fn (string $chat): array => ['driver' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => $chat];

        $this->doctor(self::config(['digest' => $tg('-100111'), 'notice' => $tg('-100111')]))->run(postTest: true);
        self::assertCount(1, $this->notifier->sent);
        self::assertSame('watcher check: test message for orders (digest, notice) - safe to ignore', $this->notifier->sent[0]['message']->text);

        $this->notifier->sent = [];
        $this->doctor(self::config(['digest' => $tg('-100111'), 'notice' => $tg('-100222')]))->run(postTest: true);
        self::assertCount(2, $this->notifier->sent);
    }

    public function testPostTestFailureHasNoSecret(): void
    {
        $this->notifier->failWith(new DeliveryFailed('telegram delivery failed: HTTP 403 ' . self::TOKEN . ' -100123'));
        $cfg = self::config(['digest' => ['driver' => 'telegram', 'bot_token' => self::TOKEN, 'chat_id' => '-100123']]);

        $r = $this->doctor($cfg)->run(postTest: true);

        self::assertFalse($r->ok());
        $out = $r->text() . json_encode($r->toArray());
        self::assertStringContainsString('delivery failed (digest, notice)', $out);
        self::assertStringNotContainsString(self::TOKEN, $out);
        self::assertStringNotContainsString('-100123', $out);
    }

    /** @return array<string, array{array<string, mixed>, bool, string}> */
    public static function stateWarningCases(): array
    {
        return [
            'redis without zlib' => [['driver' => 'redis', 'redis' => []], false, 'ext-zlib'],
            'redis without zlib, compression off' => [['driver' => 'redis', 'redis' => ['compress_min_bytes' => 0]], false, ''],
            'pdo without zlib' => [['driver' => 'pdo', 'pdo' => ['dsn' => 'x']], false, ''],
        ];
    }

    /** @param array<string, mixed> $state */
    #[DataProvider('stateWarningCases')]
    public function testStateWarnings(array $state, bool $zlib, string $expected): void
    {
        $text = implode('; ', Doctor::stateWarnings($state, $zlib));

        $expected === '' ? self::assertSame('', $text) : self::assertStringContainsString($expected, $text);
    }
}
