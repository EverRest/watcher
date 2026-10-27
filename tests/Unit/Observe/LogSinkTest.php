<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Observe;

use PHPUnit\Framework\TestCase;
use Watcher\Observe\LogSink;
use Watcher\Observe\SinkFailed;

final class LogSinkTest extends TestCase
{
    public function testWritesOneJsonObjectPerLineAndNothingElse(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $events = [
            ['schema' => 'watcher.event.v2', 'event_type' => 'run', 'title' => 'Zeitüberschreitung → 日本', 'path' => 'a/b'],
            ['schema' => 'watcher.event.v2', 'event_type' => 'group', 'rank' => 1],
        ];

        (new LogSink($stream))->emit($events);

        rewind($stream);
        $out = (string) stream_get_contents($stream);
        self::assertStringEndsWith("\n", $out);
        $lines = explode("\n", rtrim($out, "\n"));
        self::assertCount(2, $lines);
        self::assertStringStartsWith('{"schema":"watcher.event.v2",', $lines[0]);
        self::assertStringContainsString('Zeitüberschreitung → 日本', $lines[0]);
        self::assertStringContainsString('"a/b"', $lines[0]);
        self::assertSame($events[1], json_decode($lines[1], true));
    }

    public function testNoEventsWritesNothing(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        (new LogSink($stream))->emit([]);
        rewind($stream);
        self::assertSame('', stream_get_contents($stream));
    }

    public function testAWriteErrorIsASinkFailureWithoutDetail(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fclose($stream);

        try {
            (new LogSink($stream))->emit([['schema' => 'watcher.event.v2']]);
            self::fail('expected SinkFailed');
        } catch (SinkFailed $e) {
            self::assertSame('log write failed', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function testAnUnencodableEventIsASinkFailure(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        $this->expectException(SinkFailed::class);
        (new LogSink($stream))->emit([['bad' => "\xB1\x31"]]);
    }
}
