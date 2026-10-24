<?php

declare(strict_types=1);

namespace Watcher\Tests\Contract;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Redis;
use Watcher\Analyze\BudgetReservation;
use Watcher\Analyze\CostCalculator;
use Watcher\Analyze\Spend;
use Watcher\Analyze\Usage;
use Watcher\State\RedisStateStore;
use Watcher\Tests\Fakes\FrozenClock;

/** M2: the budget is reserved atomically before the model call and settled after it; needs a real Redis (WATCHER_TEST_REDIS=host:port). */
final class RedisReservationTest extends TestCase
{
    private Redis $redis;
    private string $dsn;
    private string $prefix;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $dsn = getenv('WATCHER_TEST_REDIS');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('WATCHER_TEST_REDIS (host:port) not set');
        }
        $this->dsn = $dsn;
        [$host, $port] = explode(':', $dsn);
        $this->redis = new Redis();
        $this->redis->connect($host, (int) $port);
        $this->prefix = 'watcher-test:' . bin2hex(random_bytes(6)) . ':';
        $this->clock = new FrozenClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            foreach ($this->redis->keys($this->prefix . '*') as $key) {
                $this->redis->del($key);
            }
        }
    }

    public function testParallelRunsCannotReserveMoreThanTheCap(): void
    {
        $service = 'svc' . bin2hex(random_bytes(3));
        $goAt = microtime(true) + 1.0;
        $procs = [];
        for ($i = 0; $i < 4; ++$i) {
            $p = proc_open([PHP_BINARY, __DIR__ . '/../Fixtures/reserve_child.php', $this->dsn, $this->prefix, (string) $goAt, '60', $service], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
            self::assertIsResource($p);
            $procs[] = [$p, $pipes];
        }
        $granted = 0;
        foreach ($procs as [$p, $pipes]) {
            $granted += (int) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
        }

        // 4 runs x 60 attempts of $0.10 against $1.00: exactly ten fit, however the attempts interleave.
        self::assertSame(10, $granted);
        $total = (new Spend(new RedisStateStore($this->redis, $this->clock, $this->prefix), $this->clock, new DateTimeZone('UTC'), $service))->today();
        self::assertEqualsWithDelta(1.0, $total['usd'], 1e-6);
        self::assertSame(100000, $total['tokens']);
    }

    public function testSettleBooksTheActualCostAndReleasesTheRest(): void
    {
        $spend = $this->spend();
        $r = new BudgetReservation($spend, new CostCalculator(['m' => [10, 10, 0, 0]]), 1.0, null);

        self::assertTrue($r->allows('m', 5000, 5000)); // reserves $0.10 and 10000 tokens
        self::assertEqualsWithDelta(0.10, $spend->today()['usd'], 1e-9);

        $spend->add(new Usage('m', 1000, 500), 0.015); // what the call really cost
        self::assertEqualsWithDelta(0.015, $spend->today()['usd'], 1e-9);
        self::assertSame(1500, $spend->today()['tokens']);

        $spend->add(new Usage('m', 100, 0), 0.001); // a call nobody reserved (triage-only) is simply added
        self::assertEqualsWithDelta(0.016, $spend->today()['usd'], 1e-9);
    }

    public function testReleaseGivesTheReservationBack(): void
    {
        $spend = $this->spend();
        $r = new BudgetReservation($spend, new CostCalculator(['m' => [10, 10, 0, 0]]), 1.0, null);
        self::assertTrue($r->allows('m', 5000, 5000));

        $spend->release(); // the call failed before anything was billed

        self::assertEqualsWithDelta(0.0, $spend->today()['usd'], 1e-9);
        self::assertSame(0, $spend->today()['tokens']);
        $spend->release(); // idempotent
        self::assertEqualsWithDelta(0.0, $spend->today()['usd'], 1e-9);
    }

    public function testAReservationThatDoesNotFitChangesNothing(): void
    {
        $spend = $this->spend();
        $spend->add(new Usage('m', 1, 1), 0.95);
        $r = new BudgetReservation($spend, new CostCalculator(['m' => [10, 10, 0, 0]]), 1.0, 1_000_000);

        self::assertFalse($r->allows('m', 5000, 5000));

        self::assertEqualsWithDelta(0.95, $spend->today()['usd'], 1e-9);
        self::assertSame(2, $spend->today()['tokens']);
    }

    public function testTheTokenCapIsReservedToo(): void
    {
        $spend = $this->spend();
        $r = new BudgetReservation($spend, new CostCalculator(['m' => [10, 10, 0, 0]]), 100.0, 15000);

        self::assertTrue($r->allows('m', 5000, 5000));
        self::assertFalse((new BudgetReservation($this->spend(), new CostCalculator(['m' => [10, 10, 0, 0]]), 100.0, 15000))->allows('m', 5000, 5000), 'a second run: 10000 + 10000 > 15000');
    }

    public function testBothCountersKeepATtl(): void
    {
        $spend = $this->spend();
        (new BudgetReservation($spend, new CostCalculator(['m' => [10, 10, 0, 0]]), 1.0, null))->allows('m', 10, 10);

        $keys = $this->redis->keys($this->prefix . '*');
        self::assertCount(2, $keys);
        foreach ($keys as $key) {
            self::assertGreaterThan(0, $this->redis->pttl($key), 'every reserved key expires');
        }
    }

    private function spend(): Spend
    {
        return new Spend(new RedisStateStore($this->redis, $this->clock, $this->prefix), $this->clock, new DateTimeZone('UTC'), 'svc');
    }
}
