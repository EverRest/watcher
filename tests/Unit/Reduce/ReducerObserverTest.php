<?php

declare(strict_types=1);

namespace Watcher\Tests\Unit\Reduce;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Watcher\Level;
use Watcher\Reduce\Normalizer;
use Watcher\Reduce\Reducer;
use Watcher\Source\LogEvent;

/** Spec 009 T002: the optional per-event observer; null leaves the output identical. */
final class ReducerObserverTest extends TestCase
{
    /** @return list<LogEvent> */
    private function events(): array
    {
        $t = new DateTimeImmutable('2026-10-08T01:00:00Z');

        return [
            new LogEvent($t, Level::Error, 'Alpha broke', 'AlphaException', [], ['trace_id' => 'aaaaaaaaaaaaaaaa'], []),
            new LogEvent($t, Level::Error, 'Alpha broke', 'AlphaException', [], [], []),
            new LogEvent($t, Level::Warning, 'Beta slow', null, [], [], []),
        ];
    }

    public function testTheObserverSeesEveryEventWithTheFingerprintOfItsGroup(): void
    {
        $seen = [];
        $with = (new Reducer(new Normalizer(), 3, onEvent: static function (LogEvent $e, string $fp) use (&$seen): void {
            $seen[] = [$e->message, $fp];
        }))->reduce($this->events());

        self::assertCount(3, $seen);
        self::assertSame($seen[0][1], $seen[1][1]);
        self::assertNotSame($seen[0][1], $seen[2][1]);
        self::assertContains($seen[0][1], array_map(static fn ($g): string => $g->fingerprint, $with));
        self::assertEquals((new Reducer(new Normalizer(), 3))->reduce($this->events()), $with);
    }

    public function testAThrowingObserverIsNotCaught(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Reducer(new Normalizer(), 3, onEvent: static function (): void {
            throw new \RuntimeException('ours');
        }))->reduce($this->events());
    }
}
