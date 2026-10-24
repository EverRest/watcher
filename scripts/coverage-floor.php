<?php

declare(strict_types=1);

/**
 * Fails when line coverage in build/coverage/cobertura.xml is below the floor.
 *
 *   php scripts/coverage-floor.php 92
 */
require_once __DIR__ . '/badges.php';

$floor = (float) ($argv[1] ?? 0);
$pct = badges_coverage((string) @file_get_contents(dirname(__DIR__) . '/build/coverage/cobertura.xml'));
if ($pct === null) {
    fwrite(STDERR, "no readable build/coverage/cobertura.xml\n");
    exit(2);
}
echo "line coverage {$pct}% (floor {$floor}%)\n";
exit($pct < $floor ? 1 : 0);
