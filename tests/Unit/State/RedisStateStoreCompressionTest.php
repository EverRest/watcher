<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\State;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\State\RedisStateStore;
use Watcher\State\StateCodec;
use Watcher\Tests\Fakes\FakeRedisServer;
use Watcher\Tests\Fakes\FrozenClock;
use Watcher\Tests\Fakes\SpyLogger;

final class RedisStateStoreCompressionTest extends TestCase
{
    private FakeRedisServer $server;

    private SpyLogger $log;

    protected function setUp(): void
    {
        $this->server = new FakeRedisServer(new FrozenClock(new DateTimeImmutable('2026-01-01T00:00:00Z')));
        $this->log = new SpyLogger();
    }

    private function store(int $min = 512): RedisStateStore
    {
        return new RedisStateStore($this->server, new FrozenClock(new DateTimeImmutable()), 'p:', $min, $this->log);
    }

    /** @return array<string, mixed> */
    private static function big(): array
    {
        return ['groups' => array_fill_keys(array_map(static fn (int $i): string => sprintf('%012x', $i * 7919), range(1, 200)), 3)];
    }

    public function testALargeValueIsStoredAsZ1PlusRawZlibAndReadsBack(): void
    {
        $store = $this->store();
        $store->put('snap:svc:1', self::big(), 60);

        $raw = (string) $this->server->raw('p:snap:svc:1');
        self::assertStringStartsWith('z1:', $raw);
        self::assertSame(json_encode(self::big()), gzuncompress(substr($raw, 3)));
        self::assertLessThan(strlen((string) json_encode(self::big())), strlen($raw));
        self::assertSame(self::big(), $store->get('snap:svc:1'));
    }

    public function testCompressionDoesNotChangeTheTtl(): void
    {
        $this->store()->put('snap:svc:1', self::big(), 60);
        $this->store()->put('snap:svc:2', ['a' => 1], 60);

        self::assertSame(60000, $this->server->pttl('p:snap:svc:1'));
        self::assertSame(60000, $this->server->pttl('p:snap:svc:2'));
    }

    public function testASmallValueAndTheExactThresholdStayPlainJson(): void
    {
        $small = ['a' => str_repeat('x', 100)];
        $json = (string) json_encode($small);
        $this->store(strlen($json))->put('k', $small, 60);

        self::assertSame($json, $this->server->raw('p:k'), 'larger than the threshold, not equal to it');
        $this->store(strlen($json) - 1)->put('k2', $small, 60);
        self::assertStringStartsWith('z1:', (string) $this->server->raw('p:k2'));
    }

    public function testZeroDisablesCompression(): void
    {
        $this->store(0)->put('k', self::big(), 60);

        self::assertSame(json_encode(self::big()), $this->server->raw('p:k'));
    }

    public function testCountersLocksAndMarkersAreNeverCompressed(): void
    {
        $store = $this->store(1);
        $store->increment('spend:svc', 1.5, 60);
        $store->acquire('lock:svc', str_repeat('o', 600), 60);

        self::assertSame('1.5', $this->server->raw('p:spend:svc'));
        self::assertSame(json_encode(str_repeat('o', 600)), $this->server->raw('p:lock:svc'));
        self::assertEquals(['total' => 1.5], $store->get('spend:svc'));
    }

    public function testAnOldUncompressedLargeValueStillReads(): void
    {
        $this->server->plant('p:snap:svc:old', (string) json_encode(self::big()));

        self::assertSame(self::big(), $this->store()->get('snap:svc:old'));
    }

    public function testACorruptEntryIsMissingCountedAndLoggedByKindOnly(): void
    {
        $this->server->plant('p:snap:secret-service:1', 'z1:' . 'not zlib at all hunter2');
        $store = $this->store();

        self::assertNull($store->get('snap:secret-service:1'));
        self::assertSame(1, $store->unreadable);
        self::assertCount(1, $this->log->records);
        $line = json_encode($this->log->records[0]);
        self::assertStringContainsString('snap', (string) $line);
        self::assertStringNotContainsString('secret-service', (string) $line);
        self::assertStringNotContainsString('hunter2', (string) $line);
    }

    public function testValidZlibOfNonJsonOrNonArrayIsUnreadable(): void
    {
        $this->server->plant('p:a', 'z1:' . gzcompress('{broken', 6));
        $this->server->plant('p:b', 'z1:' . gzcompress('"text"', 6));
        $store = $this->store();

        self::assertNull($store->get('a'));
        self::assertNull($store->get('b'));
        self::assertSame(2, $store->unreadable);
    }

    public function testADecompressedSizeOverTheCapIsMissing(): void
    {
        $bomb = gzcompress((string) json_encode(['x' => str_repeat('0', StateCodec::MAX_BYTES + 1024)]), 6);
        self::assertLessThan(100000, strlen((string) $bomb), 'the fixture must be small compressed');
        $this->server->plant('p:bomb', 'z1:' . $bomb);
        $store = $this->store();

        self::assertNull($store->get('bomb'));
        self::assertSame(1, $store->unreadable);
    }

    public function testTheCapIsFourMegabytes(): void
    {
        self::assertSame(4 * 1024 * 1024, StateCodec::MAX_BYTES);
    }

    public function testJustOverTheCapAndANumericArrayBombAreMissingNeverOom(): void
    {
        $over = gzcompress('{"x":"' . str_repeat('0', StateCodec::MAX_BYTES) . '"}', 6); // 4 MB + 9 bytes
        $bomb = gzcompress('[' . str_repeat('0,', 8 * 1024 * 1024 - 1) . '0]', 6); // 16 MB of ints: ~8M array slots
        self::assertLessThan(100000, strlen((string) $bomb));
        $this->server->plant('p:over', 'z1:' . $over);
        $this->server->plant('p:bomb', 'z1:' . $bomb);
        $this->server->plant('p:plain', '[' . str_repeat('0,', 8 * 1024 * 1024 - 1) . '0]'); // uncompressed, same size
        $store = $this->store();

        self::assertNull($store->get('over'));
        self::assertNull($store->get('bomb'));
        self::assertNull($store->get('plain'));
        self::assertSame(3, $store->unreadable); // the plain oversize value is refused like the compressed ones
    }

    public function testABombIsRejectedWithMemoryBoundedByTheCapPlusASlice(): void
    {
        // 200 MB of zeros (~200 KB compressed). Run in a child so the parent's own fixture does not count; the child
        // measures its peak around decode(). Dropping the running-total check or the chunk loop fails this.
        $script = sys_get_temp_dir() . '/watcher-bomb-' . getmypid() . '.php';
        file_put_contents($script . '.z', (string) gzcompress(str_repeat('0', 200 * 1024 * 1024), 1));
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . '$z = "z1:" . file_get_contents(' . var_export($script . '.z', true) . ');'
            . '$base = memory_get_peak_usage(); try { Watcher\\State\\StateCodec::decode($z); $r = "decoded"; } catch (UnexpectedValueException) { $r = "refused"; }'
            . 'echo $r, " ", memory_get_peak_usage() - $base;');
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 -d xdebug.mode=off -d memory_limit=1G ' . escapeshellarg($script) . ' 2>&1');
        @unlink($script);
        @unlink($script . '.z');

        self::assertMatchesRegularExpression('/^refused \d+$/', $out);
        self::assertLessThan(StateCodec::MAX_BYTES + 8 * 1024 * 1024, (int) substr($out, 8), 'peak delta ' . $out);
    }

    public function testTheCapPlusOneIsRefusedAndTheCapItselfReads(): void
    {
        $pad = static fn (int $bytes): string => '{"x":"' . str_repeat('a', $bytes - 8) . '"}';
        self::assertSame(StateCodec::MAX_BYTES, strlen($pad(StateCodec::MAX_BYTES)));
        self::assertIsArray(StateCodec::decode('z1:' . gzcompress($pad(StateCodec::MAX_BYTES), 6)));
        $this->expectException(\UnexpectedValueException::class);
        StateCodec::decode('z1:' . gzcompress($pad(StateCodec::MAX_BYTES + 1), 6));
    }

    public function testATruncatedStreamAndTrailingBytesAreUnreadable(): void
    {
        $z = (string) gzcompress((string) json_encode(self::big()), 6);
        $this->server->plant('p:trunc', 'z1:' . substr($z, 0, intdiv(strlen($z), 2)));
        $this->server->plant('p:trail', 'z1:' . $z . str_repeat('garbage', 300));
        $this->server->plant('p:ok', 'z1:' . $z);
        $store = $this->store();

        self::assertNull($store->get('trunc'));
        self::assertNull($store->get('trail'));
        self::assertSame(self::big(), $store->get('ok'));
        self::assertSame(2, $store->unreadable);
    }

    /** Runs StateCodec::decode on each payload in a child with memory_limit=128M; returns "<outcome> <peak delta bytes>" per payload. */
    private static function child(string ...$raws): string
    {
        $script = sys_get_temp_dir() . '/watcher-m1-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.php';
        $files = [];
        foreach ($raws as $i => $raw) {
            $files[] = $script . ".$i";
            file_put_contents($script . ".$i", $raw);
        }
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . 'foreach (' . var_export($files, true) . ' as $f) { $raw = file_get_contents($f); $base = memory_get_peak_usage();'
            . ' try { $v = Watcher\\State\\StateCodec::decode($raw); $r = is_array($v) ? "decoded" : "null"; } catch (UnexpectedValueException) { $r = "unreadable"; }'
            . ' echo $r, " ", memory_get_peak_usage() - $base, "\n"; unset($v, $raw); }');
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 -d xdebug.mode=off -d memory_limit=128M ' . escapeshellarg($script) . ' 2>&1');
        @unlink($script);
        array_map('unlink', $files);

        return $out;
    }

    public function testTheDecodedStructureIsBudgetedSoNoValueCanExhaustMemory(): void
    {
        // 4 MB of `[0],` is valid JSON that decodes to ~237 MB: the old byte cap alone ended in an uncatchable fatal.
        $deep = '[' . str_repeat('[0],', 1_000_000) . '[0]]';
        self::assertLessThanOrEqual(StateCodec::MAX_BYTES, strlen($deep));
        $flat = '[' . str_repeat('0,', 2_000_000) . '0]';
        $lines = explode("\n", trim(self::child($deep, 'z1:' . gzcompress($deep, 6), $flat, 'z1:' . gzcompress($flat, 6))));

        self::assertCount(4, $lines, 'the child must survive all four: ' . implode('|', $lines));
        foreach ($lines as $line) {
            self::assertMatchesRegularExpression('/^unreadable \d+$/', $line);
            self::assertLessThan(16 * 1024 * 1024, (int) substr($line, 11), $line);
        }
    }

    public function testTheWorstAcceptedValueStaysWellUnderTheMemoryLimit(): void
    {
        $map = [];
        for ($i = 0; $i < StateCodec::MAX_ELEMENTS - 2 * StateCodec::MAX_CONTAINERS - 20; $i++) {
            $map['k' . $i] = 0;
        }
        $lists = array_fill(0, StateCodec::MAX_CONTAINERS - 5, ['a' => 0]);
        $json = (string) json_encode(['m' => $map, 'l' => $lists]);
        self::assertTrue(StateCodec::fits($json), 'the fixture must be accepted');
        $line = trim(self::child($json));

        self::assertMatchesRegularExpression('/^decoded \d+$/', $line);
        self::assertLessThan(32 * 1024 * 1024, (int) substr($line, 8), $line);
    }

    public function testAValueTheReaderWouldRefuseIsRefusedAtWrite(): void
    {
        $this->expectException(\Watcher\State\StateValueTooLarge::class);
        StateCodec::encode(['x' => range(1, StateCodec::MAX_ELEMENTS + 1)], 512);
    }

    public function testTheStoreRefusesToPutAValueItCouldNotReadBack(): void
    {
        $store = $this->store();
        try {
            $store->put('snap:svc:1', ['x' => str_repeat('a', StateCodec::MAX_BYTES)], 60);
            self::fail('an oversize value must not be written');
        } catch (\Watcher\State\StateValueTooLarge $e) {
            self::assertStringNotContainsString('svc', $e->getMessage());
        }
        self::assertNull($this->server->raw('p:snap:svc:1'));
    }

    public function testTrailingBytesAfterTheStreamAreRefusedWhereverTheyFall(): void
    {
        $z = (string) gzcompress((string) json_encode(self::big()), 6);
        $store = $this->store();
        foreach (['x', 'garbage', str_repeat('g', 5000)] as $i => $tail) {
            $this->server->plant("p:t$i", 'z1:' . $z . $tail);
            self::assertNull($store->get("t$i"), 'tail ' . $i);
        }
        self::assertSame(3, $store->unreadable);
    }

    public function testEncodeSkipsCompressionWithoutZlibAndDecodeRefusesZ1(): void
    {
        self::assertStringStartsWith('{', StateCodec::encode(self::big(), 512, false));
        $this->expectException(\UnexpectedValueException::class);
        StateCodec::decode('z1:' . gzcompress('{}', 6), false);
    }

    public function testAHealthyReadDoesNotCount(): void
    {
        $store = $this->store();
        $store->put('k', self::big(), 60);
        $store->get('k');
        $store->get('missing');

        self::assertSame(0, $store->unreadable);
        self::assertSame([], $this->log->records);
    }
}
