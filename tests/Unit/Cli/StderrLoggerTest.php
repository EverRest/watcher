<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Watcher\Cli\StderrLogger;

final class StderrLoggerTest extends TestCase
{
    /** @return array{StderrLogger, resource} */
    private function logger(): array
    {
        $s = fopen('php://memory', 'w+');
        self::assertIsResource($s);

        return [new StderrLogger($s), $s];
    }

    /** @param resource $s */
    private function read($s): string
    {
        rewind($s);

        return (string) stream_get_contents($s);
    }

    public function testWritesLevelAndMessageOnly(): void
    {
        [$log, $s] = $this->logger();
        $log->error('run failed: slack delivery failed: HTTP 404', ['webhook' => 'https://hooks.example/secret', 'key' => 'sk-secret']);

        $out = $this->read($s);
        self::assertSame("watcher [error] run failed: slack delivery failed: HTTP 404\n", $out);
        self::assertStringNotContainsString('secret', $out);
    }

    public function testLineBreaksInTheMessageCannotForgeLogLines(): void
    {
        [$log, $s] = $this->logger();
        $log->warning("first\nwatcher [error] forged\r\nline");

        $out = $this->read($s);
        self::assertSame(1, substr_count($out, "\n"));
        self::assertStringEndsWith("\n", $out);
    }
}
