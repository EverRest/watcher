<?php

declare(strict_types=1);

namespace Watcher\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;

/** Watcher::attempt() arms the FatalGuard: a PHP fatal in a run frees the lock and logs the error type (A4-02), end to end. */
final class FatalGuardWiringTest extends TestCase
{
    public function testAMemoryFatalReleasesTheRunLockAndIsLogged(): void
    {
        $root = dirname(__DIR__, 2);
        $script = __DIR__ . '/../Fixtures/fatal_child.php';
        $p = proc_open(
            [PHP_BINARY, '-n', '-d', 'memory_limit=48M', '-d', 'display_errors=0', $script],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $root,
        );
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($p);

        self::assertStringContainsString('LOG watcher run aborted by a PHP fatal error (E_ERROR)', $out);
        self::assertStringContainsString('LOCK released', $out);
    }
}
