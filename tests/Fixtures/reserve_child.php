<?php

declare(strict_types=1);

// Child process of RedisReservationTest: one "run" that waits for the start time, then tries to reserve budget steps and holds them.
use Watcher\Analyze\BudgetReservation;
use Watcher\Analyze\CostCalculator;
use Watcher\Analyze\Spend;
use Watcher\Schedule\SystemClock;
use Watcher\State\RedisStateStore;

// The parent reads this process's stdout as data: old dependencies (lowest resolution on a new PHP) print deprecation notices there.
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $dsn, $prefix, $goAt, $attempts, $service] = $argv;
[$host, $port] = explode(':', $dsn);
$redis = new Redis();
$redis->connect($host, (int) $port);
$clock = new SystemClock();
$store = new RedisStateStore($redis, $clock, $prefix);

while (microtime(true) < (float) $goAt) {
    usleep(50);
}
$granted = 0;
for ($i = 0; $i < (int) $attempts; ++$i) {
    // A new Spend per attempt = a new run: a reservation is only released by its own run.
    $spend = new Spend($store, $clock, new DateTimeZone('UTC'), $service);
    if ((new BudgetReservation($spend, new CostCalculator(['m' => [10, 10, 0, 0]]), 1.0, null))->allows('m', 5000, 5000)) {
        ++$granted; // $0.10 worst case; never settled here, the model call is "in progress"
    }
}
echo $granted, "\n";
