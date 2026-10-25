<?php

declare(strict_types=1);

// Child process of RedisReservationTest: runs that reserve just before midnight and settle (even attempts) or release (odd) just after it.
use Watcher\Analyze\BudgetReservation;
use Watcher\Analyze\CostCalculator;
use Watcher\Analyze\Spend;
use Watcher\Analyze\Usage;
use Watcher\State\RedisStateStore;
use Watcher\Tests\Fakes\FrozenClock;

// The parent reads this process's stdout as data: old dependencies (lowest resolution on a new PHP) print deprecation notices there.
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $dsn, $prefix, $goAt, $attempts, $service] = $argv;
[$host, $port] = explode(':', $dsn);
$redis = new Redis();
$redis->connect($host, (int) $port);

while (microtime(true) < (float) $goAt) {
    usleep(50);
}
$settled = 0;
for ($i = 0; $i < (int) $attempts; ++$i) {
    $clock = new FrozenClock(new DateTimeImmutable('2026-10-23T23:59:59+00:00'));
    $spend = new Spend(new RedisStateStore($redis, $clock, $prefix), $clock, new DateTimeZone('UTC'), $service);
    if (!(new BudgetReservation($spend, new CostCalculator(['m' => [10, 10, 0, 0]]), 1.0, null))->allows('m', 5000, 5000)) {
        continue;
    }
    $clock->set(new DateTimeImmutable('2026-10-24T00:00:01+00:00'));
    if ($i % 2 === 0) {
        $spend->add(new Usage('m', 100, 100), 0.04);
        ++$settled;
    } else {
        $spend->release();
    }
}
echo $settled, "\n";
